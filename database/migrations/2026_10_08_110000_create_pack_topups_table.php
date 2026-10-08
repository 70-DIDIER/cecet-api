<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Top-ups of a Personnel pack ("rechargements Perso"): the member chooses the amount, pays it, and the pack is
     * credited with the YEM it buys. The discount and the rate are snapshotted when the top-up is requested.
     */
    public function up(): void
    {
        Schema::create('pack_topups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('user_pack_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->unsignedBigInteger('amount_xof');
            $table->unsignedInteger('discount_bps');
            $table->string('rate_setting_key', 60);
            $table->unsignedInteger('rate_xof_per_yem');
            $table->decimal('quantity', 28, 6);
            $table->timestampTz('credited_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_pack_id', 'created_at']);
        });

        DB::statement("ALTER TABLE pack_topups ADD CONSTRAINT pack_topups_status_check CHECK (status IN ('pending_payment', 'credited', 'canceled'))");
        DB::statement('ALTER TABLE pack_topups ADD CONSTRAINT pack_topups_values_check CHECK (amount_xof > 0 AND quantity > 0 AND rate_xof_per_yem > 0 AND discount_bps < 10000)');
        DB::statement("CREATE UNIQUE INDEX pack_topups_one_pending_per_pack ON pack_topups (user_pack_id) WHERE status = 'pending_payment'");
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_topups');
    }
};
