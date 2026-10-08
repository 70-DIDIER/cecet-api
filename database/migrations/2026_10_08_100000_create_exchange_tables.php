<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * YEM sold to the CECT: the request (quota reserved), the Validator's decisions, and the member's payout destination.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS exchange_reference_seq START 1');

        Schema::create('payout_destinations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('method', 30);
            $table->string('channel', 20);
            $table->string('phone', 20)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('account_holder', 160)->nullable();
            $table->string('account_number', 60)->nullable();
            $table->timestampsTz();
        });

        Schema::create('exchange_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('reference', 20)->unique();
            $table->string('kind', 30);
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('user_pack_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->decimal('quantity_requested', 28, 6);
            $table->decimal('quantity_received', 28, 6)->nullable();
            $table->string('rate_setting_key', 120);
            $table->unsignedInteger('rate_xof_per_yem');
            $table->unsignedBigInteger('amount_due_xof')->nullable();
            $table->string('source_pernum', 64);
            $table->string('cect_pernum', 64);
            $table->jsonb('payout_destination');
            $table->string('member_transfer_reference', 120)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('declared_at')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->foreignUlid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE exchange_requests ADD CONSTRAINT exchange_requests_status_check CHECK (status IN ('awaiting_transfer', 'declared_sent', 'yem_received', 'completed', 'canceled', 'expired', 'rejected', 'disputed'))");
        DB::statement('ALTER TABLE exchange_requests ADD CONSTRAINT exchange_requests_quantity_check CHECK (quantity_requested > 0 AND (quantity_received IS NULL OR (quantity_received > 0 AND quantity_received <= quantity_requested)))');
        DB::statement("ALTER TABLE exchange_requests ADD CONSTRAINT exchange_requests_received_check CHECK (status NOT IN ('yem_received', 'completed') OR (quantity_received IS NOT NULL AND amount_due_xof IS NOT NULL AND validated_by IS NOT NULL))");

        Schema::create('validation_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('exchange_request_id')->constrained()->restrictOnDelete();
            $table->string('action', 20);
            $table->decimal('quantity_received', 28, 6)->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->text('comment')->nullable();
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at', 6);

            $table->index(['exchange_request_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER validation_events_append_only
                BEFORE UPDATE OR DELETE ON validation_events
                FOR EACH ROW EXECUTE FUNCTION forbid_append_only_mutation();

            CREATE TRIGGER validation_events_no_truncate
                BEFORE TRUNCATE ON validation_events
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_append_only_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_events');
        Schema::dropIfExists('exchange_requests');
        Schema::dropIfExists('payout_destinations');
        DB::statement('DROP SEQUENCE IF EXISTS exchange_reference_seq');
    }
};
