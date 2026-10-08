<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payments of a Consommateur/Personnel member to a merchant of the network (cahier v4.0 §6.2, §23.3, §24.2).
     *
     * - merchant_qr_codes: opaque tokens printed by merchants; the server resolves the merchant and its current
     *   Pernum, never trusting what a QR would carry. Revocable and rotatable.
     * - merchant_payments: the intent created after a scan. The payer's quantity is reserved on their pack; nothing
     *   moves at the scan, and the real transfer happens outside the platform.
     * - merchant_accounts / merchant_ledger_entries: the merchant's own ledger (collections confirmed and eligible
     *   for redemption, reserved for a redemption, redeemed), distinct from the quota ledger.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS merchant_payment_reference_seq START 1');

        Schema::create('merchant_qr_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('user_pack_id')->constrained()->restrictOnDelete();
            $table->string('token', 64)->unique();
            $table->string('outlet_label', 120)->nullable();
            $table->unsignedInteger('version');
            $table->string('status', 20);
            $table->ulid('replaces_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
        });

        Schema::table('merchant_qr_codes', function (Blueprint $table) {
            $table->foreign('replaces_id')->references('id')->on('merchant_qr_codes')->nullOnDelete();
        });

        DB::statement("ALTER TABLE merchant_qr_codes ADD CONSTRAINT merchant_qr_codes_status_check CHECK (status IN ('active', 'revoked'))");

        Schema::create('merchant_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('reference', 20)->unique();
            $table->foreignUlid('payer_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('payer_pack_id')->constrained('user_packs')->restrictOnDelete();
            $table->foreignUlid('merchant_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('merchant_pack_id')->constrained('user_packs')->restrictOnDelete();
            $table->foreignUlid('qr_code_id')->nullable()->constrained('merchant_qr_codes')->restrictOnDelete();
            $table->string('status', 20);
            $table->decimal('quantity', 28, 6);
            $table->string('payer_pernum', 32)->nullable();
            $table->string('merchant_pernum', 32);
            $table->string('outlet_label', 120)->nullable();
            $table->string('confirmation_mode', 20);
            $table->string('payer_transfer_reference', 120)->nullable();
            $table->text('dispute_reason')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('declared_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->foreignUlid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index(['payer_id', 'created_at']);
            $table->index(['merchant_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement("ALTER TABLE merchant_payments ADD CONSTRAINT merchant_payments_status_check CHECK (status IN ('reserved', 'declared_sent', 'confirmed', 'canceled', 'expired', 'disputed', 'rejected'))");
        DB::statement("ALTER TABLE merchant_payments ADD CONSTRAINT merchant_payments_mode_check CHECK (confirmation_mode IN ('merchant', 'validator'))");
        DB::statement('ALTER TABLE merchant_payments ADD CONSTRAINT merchant_payments_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE merchant_payments ADD CONSTRAINT merchant_payments_parties_check CHECK (payer_id <> merchant_id)');
        DB::statement("ALTER TABLE merchant_payments ADD CONSTRAINT merchant_payments_confirmed_check CHECK (status <> 'confirmed' OR (confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL))");

        Schema::create('merchant_accounts', function (Blueprint $table) {
            $table->foreignUlid('user_pack_id')->primary()->constrained()->restrictOnDelete();
            $table->decimal('eligible', 28, 6)->default(0);
            $table->decimal('reserved', 28, 6)->default(0);
            $table->decimal('redeemed', 28, 6)->default(0);
            $table->unsignedBigInteger('entries_count')->default(0);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE merchant_accounts ADD CONSTRAINT merchant_accounts_non_negative_check CHECK (eligible >= 0 AND reserved >= 0 AND redeemed >= 0)');

        Schema::create('merchant_ledger_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_pack_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('eligible_delta', 28, 6);
            $table->decimal('reserved_delta', 28, 6);
            $table->decimal('redeemed_delta', 28, 6);
            $table->decimal('eligible_after', 28, 6);
            $table->decimal('reserved_after', 28, 6);
            $table->decimal('redeemed_after', 28, 6);
            $table->string('idempotency_key', 190)->unique();
            $table->string('source_type', 60)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->text('reason')->nullable();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('created_at', 6);

            $table->index(['user_pack_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE merchant_ledger_entries ADD CONSTRAINT merchant_ledger_entries_shape_check CHECK (
                (type = 'credit'  AND eligible_delta > 0 AND reserved_delta = 0 AND redeemed_delta = 0)
                OR (type = 'reserve' AND eligible_delta < 0 AND reserved_delta = -eligible_delta AND redeemed_delta = 0)
                OR (type = 'release' AND reserved_delta < 0 AND eligible_delta = -reserved_delta AND redeemed_delta = 0)
                OR (type = 'consume' AND reserved_delta < 0 AND redeemed_delta = -reserved_delta AND eligible_delta = 0)
                OR (type = 'adjust'  AND eligible_delta <> 0 AND reserved_delta = 0 AND redeemed_delta = 0)
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER merchant_ledger_entries_append_only
                BEFORE UPDATE OR DELETE ON merchant_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION forbid_append_only_mutation();

            CREATE TRIGGER merchant_ledger_entries_no_truncate
                BEFORE TRUNCATE ON merchant_ledger_entries
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_append_only_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_ledger_entries');
        Schema::dropIfExists('merchant_accounts');
        Schema::dropIfExists('merchant_payments');
        Schema::dropIfExists('merchant_qr_codes');
        DB::statement('DROP SEQUENCE IF EXISTS merchant_payment_reference_seq');
    }
};
