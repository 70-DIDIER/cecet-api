<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Counter-values owed in FCFA: one batch per obligation, split into technical items (Mobile Money transfers
     * or a manual settlement), plus the transactional outbox that hands work to the queue reliably.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS payout_reference_seq START 1');

        Schema::create('payout_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('reference', 20)->unique();
            $table->ulidMorphs('payable');
            $table->foreignUlid('beneficiary_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('total_amount');
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedBigInteger('remaining_amount');
            $table->char('currency', 3)->default('XOF');
            $table->string('route', 20);
            $table->string('method', 30);
            $table->jsonb('destination');
            $table->string('status', 20);
            $table->string('blocked_reason', 60)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->unique(['payable_type', 'payable_id']);
            $table->index(['status', 'created_at']);
        });

        DB::statement('ALTER TABLE payout_batches ADD CONSTRAINT payout_batches_amounts_check CHECK (total_amount > 0 AND paid_amount + remaining_amount = total_amount)');
        DB::statement("ALTER TABLE payout_batches ADD CONSTRAINT payout_batches_status_check CHECK (status IN ('awaiting_dispatch', 'processing', 'partially_paid', 'paid', 'failed', 'manual_required'))");

        Schema::create('payout_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payout_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('kind', 20);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->unsignedBigInteger('transfer_amount')->nullable();
            $table->string('status', 20);
            $table->string('provider', 30)->nullable();
            $table->string('provider_reference', 120)->nullable();
            $table->string('merchant_reference', 120)->nullable()->unique();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();

            $table->unique(['payout_batch_id', 'sequence']);
            $table->index(['status', 'submitted_at']);
        });

        DB::statement("ALTER TABLE payout_items ADD CONSTRAINT payout_items_status_check CHECK (status IN ('pending', 'processing', 'succeeded', 'failed', 'canceled'))");
        DB::statement("ALTER TABLE payout_items ADD CONSTRAINT payout_items_kind_check CHECK (kind IN ('mobile_money', 'manual'))");
        DB::statement('ALTER TABLE payout_items ADD CONSTRAINT payout_items_amount_check CHECK (amount > 0 AND fee_amount <= amount)');

        Schema::create('manual_settlements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payout_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('method', 30);
            $table->string('reference', 120);
            $table->text('note')->nullable();
            $table->timestampTz('paid_at');
            $table->string('status', 20);
            $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE manual_settlements ADD CONSTRAINT manual_settlements_status_check CHECK (status IN ('pending_approval', 'applied', 'rejected'))");
        // Maker-checker: whoever approves or rejects a settlement can never be the person who recorded it.
        DB::statement('ALTER TABLE manual_settlements ADD CONSTRAINT manual_settlements_maker_checker_check CHECK (decided_by IS NULL OR decided_by <> recorded_by)');
        DB::statement("CREATE UNIQUE INDEX manual_settlements_one_pending_per_batch ON manual_settlements (payout_batch_id) WHERE status = 'pending_approval'");

        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 60);
            $table->jsonb('payload');
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('available_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('manual_settlements');
        Schema::dropIfExists('payout_items');
        Schema::dropIfExists('payout_batches');
        DB::statement('DROP SEQUENCE IF EXISTS payout_reference_seq');
    }
};
