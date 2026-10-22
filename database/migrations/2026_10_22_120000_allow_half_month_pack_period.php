<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pack_versions DROP CONSTRAINT pack_versions_period_unit_check');
        DB::statement("ALTER TABLE pack_versions ADD CONSTRAINT pack_versions_period_unit_check CHECK (period_unit IS NULL OR period_unit IN ('day', 'week', 'month', 'half_month'))");
    }

    public function down(): void
    {
        // Roll back only when no immutable version uses the newly introduced unit.
        if (DB::table('pack_versions')->where('period_unit', 'half_month')->exists()) {
            throw new RuntimeException('Cannot roll back half-month support while published contracts reference it.');
        }
        DB::statement('ALTER TABLE pack_versions DROP CONSTRAINT pack_versions_period_unit_check');
        DB::statement("ALTER TABLE pack_versions ADD CONSTRAINT pack_versions_period_unit_check CHECK (period_unit IS NULL OR period_unit IN ('day', 'week', 'month'))");
    }
};
