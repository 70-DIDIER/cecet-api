<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purchased packs, their periods, and the quota ledger.
     *
     * The ledger is the source of truth (append-only, one signed delta per bucket); quota_accounts is a
     * projection updated in the same transaction, locked per pack, and protected by non-negative checks.
     */
    public function up(): void
    {
        Schema::create('user_packs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('pack_plan_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('pack_version_id')->constrained()->restrictOnDelete();
            $table->string('family', 20);
            $table->string('status', 20);
            $table->unsignedBigInteger('price_xof');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement("ALTER TABLE user_packs ADD CONSTRAINT user_packs_status_check CHECK (status IN ('pending_payment', 'active', 'expired', 'canceled'))");
        DB::statement("CREATE UNIQUE INDEX user_packs_one_pending_per_plan ON user_packs (user_id, pack_plan_id) WHERE status = 'pending_payment'");

        Schema::create('pack_periods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_pack_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('number');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->decimal('quota', 28, 6);
            $table->timestampTz('credited_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_pack_id', 'number']);
            $table->index(['starts_at', 'credited_at']);
        });

        Schema::create('quota_accounts', function (Blueprint $table) {
            $table->foreignUlid('user_pack_id')->primary()->constrained()->restrictOnDelete();
            $table->decimal('available', 28, 6)->default(0);
            $table->decimal('reserved', 28, 6)->default(0);
            $table->decimal('consumed', 28, 6)->default(0);
            $table->unsignedBigInteger('entries_count')->default(0);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE quota_accounts ADD CONSTRAINT quota_accounts_non_negative_check CHECK (available >= 0 AND reserved >= 0 AND consumed >= 0)');

        Schema::create('quota_ledger_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_pack_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('available_delta', 28, 6);
            $table->decimal('reserved_delta', 28, 6);
            $table->decimal('consumed_delta', 28, 6);
            $table->decimal('available_after', 28, 6);
            $table->decimal('reserved_after', 28, 6);
            $table->decimal('consumed_after', 28, 6);
            $table->string('idempotency_key', 190)->unique();
            $table->string('source_type', 60)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->foreignUlid('pack_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('created_at', 6);

            $table->index(['user_pack_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE quota_ledger_entries ADD CONSTRAINT quota_ledger_entries_shape_check CHECK (
                (type = 'credit'  AND available_delta > 0 AND reserved_delta = 0 AND consumed_delta = 0)
                OR (type = 'reserve' AND available_delta < 0 AND reserved_delta = -available_delta AND consumed_delta = 0)
                OR (type = 'release' AND reserved_delta < 0 AND available_delta = -reserved_delta AND consumed_delta = 0)
                OR (type = 'consume' AND reserved_delta < 0 AND consumed_delta = -reserved_delta AND available_delta = 0)
                OR (type = 'expire'  AND available_delta < 0 AND reserved_delta = 0 AND consumed_delta = 0)
                OR (type = 'adjust'  AND available_delta <> 0 AND reserved_delta = 0 AND consumed_delta = 0)
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER quota_ledger_entries_append_only
                BEFORE UPDATE OR DELETE ON quota_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION forbid_append_only_mutation();

            CREATE TRIGGER quota_ledger_entries_no_truncate
                BEFORE TRUNCATE ON quota_ledger_entries
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_append_only_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('quota_ledger_entries');
        Schema::dropIfExists('quota_accounts');
        Schema::dropIfExists('pack_periods');
        Schema::dropIfExists('user_packs');
    }
};
