<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membership of a member: the fee snapshot at the time of payment and the activation trace.
     */
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->unsignedBigInteger('fee_amount');
            $table->char('fee_currency', 3)->default('XOF');
            $table->string('fee_setting_key', 120);
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->string('activation_mode', 30)->nullable();
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_status_check CHECK (status IN ('pending_payment', 'active'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
