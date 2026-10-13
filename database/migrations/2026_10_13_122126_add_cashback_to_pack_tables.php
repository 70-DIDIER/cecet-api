<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cashback in YEM replaces the discount on the price: the member pays the full amount and receives a bonus in YEM.
     * The calculation (rate, base, cashback, rounding, version of the rule) is frozen on the purchase.
     */
    public function up(): void
    {
        Schema::table('pack_topups', function (Blueprint $table): void {
            $table->dropColumn('discount_bps');
        });

        Schema::table('pack_topups', function (Blueprint $table): void {
            $table->unsignedInteger('cashback_bps')->default(0);
            $table->decimal('yem_base', 28, 6)->default(0);
            $table->decimal('yem_cashback', 28, 6)->default(0);
            $table->string('cashback_rounding', 10)->default('down');
            $table->string('cashback_rule_effective_from', 40)->nullable();
            $table->timestampTz('calculated_at')->nullable();
        });

        DB::statement('ALTER TABLE pack_topups DROP CONSTRAINT IF EXISTS pack_topups_values_check');
        DB::statement('ALTER TABLE pack_topups ADD CONSTRAINT pack_topups_values_check CHECK (amount_xof > 0 AND quantity > 0 AND rate_xof_per_yem > 0)');
        DB::statement('ALTER TABLE pack_topups ADD CONSTRAINT pack_topups_cashback_check CHECK (cashback_bps < 10000 AND yem_base >= 0 AND yem_cashback >= 0)');

        Schema::table('user_packs', function (Blueprint $table): void {
            $table->unsignedInteger('cashback_bps')->default(0);
            $table->decimal('yem_base', 28, 6)->nullable();
            $table->decimal('yem_cashback', 28, 6)->default(0);
            $table->string('cashback_rounding', 10)->default('down');
            $table->string('cashback_rule_effective_from', 40)->nullable();
            $table->timestampTz('calculated_at')->nullable();
        });

        Schema::table('pack_periods', function (Blueprint $table): void {
            $table->decimal('cashback_quota', 28, 6)->default(0);
        });

        DB::statement('ALTER TABLE quota_ledger_entries DROP CONSTRAINT quota_ledger_entries_shape_check');
        DB::statement(<<<'SQL'
            ALTER TABLE quota_ledger_entries ADD CONSTRAINT quota_ledger_entries_shape_check CHECK (
                (type IN ('credit', 'cashback_credit') AND available_delta > 0 AND reserved_delta = 0 AND consumed_delta = 0)
                OR (type = 'reserve' AND available_delta < 0 AND reserved_delta = -available_delta AND consumed_delta = 0)
                OR (type = 'release' AND reserved_delta < 0 AND available_delta = -reserved_delta AND consumed_delta = 0)
                OR (type = 'consume' AND reserved_delta < 0 AND consumed_delta = -reserved_delta AND available_delta = 0)
                OR (type = 'expire'  AND available_delta < 0 AND reserved_delta = 0 AND consumed_delta = 0)
                OR (type = 'adjust'  AND available_delta <> 0 AND reserved_delta = 0 AND consumed_delta = 0)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE quota_ledger_entries DROP CONSTRAINT quota_ledger_entries_shape_check');
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

        Schema::table('pack_periods', function (Blueprint $table): void {
            $table->dropColumn('cashback_quota');
        });

        Schema::table('user_packs', function (Blueprint $table): void {
            $table->dropColumn(['cashback_bps', 'yem_base', 'yem_cashback', 'cashback_rounding', 'cashback_rule_effective_from', 'calculated_at']);
        });

        DB::statement('ALTER TABLE pack_topups DROP CONSTRAINT pack_topups_cashback_check');

        Schema::table('pack_topups', function (Blueprint $table): void {
            $table->dropColumn(['cashback_bps', 'yem_base', 'yem_cashback', 'cashback_rounding', 'cashback_rule_effective_from', 'calculated_at']);
            $table->unsignedInteger('discount_bps')->default(0);
        });
    }
};
