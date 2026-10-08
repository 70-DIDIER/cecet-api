<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inbound payments: an intent (what must be paid), its attempts (provider checkouts)
     * and the webhook events received from providers.
     */
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 30);
            $table->ulidMorphs('payable');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
        });

        DB::statement('ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_total_check CHECK (total_amount = amount + fee_amount)');
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_status_check CHECK (status IN ('open', 'succeeded', 'canceled'))");
        DB::statement("CREATE UNIQUE INDEX payment_intents_one_open_per_payable ON payment_intents (payable_type, payable_id) WHERE status = 'open'");

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_intent_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('method', 30)->nullable();
            $table->string('declared_reference', 120)->nullable();
            $table->timestampTz('declared_at')->nullable();
            $table->foreignUlid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('validated_at')->nullable();
            $table->text('validation_comment')->nullable();
            $table->string('provider_transaction_id', 120)->nullable();
            $table->string('provider_reference', 120)->nullable();
            $table->string('status', 20);
            $table->text('checkout_url')->nullable();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->char('currency', 3);
            $table->boolean('requires_review')->default(false);
            $table->string('review_reason', 60)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_transaction_id']);
            $table->index(['payment_intent_id', 'status']);
            $table->index('requires_review');
        });

        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_status_check CHECK (status IN ('created', 'pending', 'approved', 'declined', 'canceled', 'expired', 'failed', 'mismatch'))");

        Schema::create('provider_webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider', 30);
            $table->string('provider_event_id', 190);
            $table->string('event_name', 120)->nullable();
            $table->string('provider_transaction_id', 120)->nullable();
            $table->jsonb('payload');
            $table->string('status', 20);
            $table->timestampTz('received_at', 6);
            $table->timestampTz('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->unique(['provider', 'provider_event_id']);
            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_webhook_events');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payment_intents');
    }
};
