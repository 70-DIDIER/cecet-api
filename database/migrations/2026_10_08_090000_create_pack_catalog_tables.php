<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pack catalog: plans (commercial products with a lifecycle) and versions (immutable terms once published).
     */
    public function up(): void
    {
        Schema::create('pack_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('family', 20);
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->unsignedSmallInteger('level')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20);
            $table->timestampTz('published_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['family', 'status']);
        });

        DB::statement("ALTER TABLE pack_plans ADD CONSTRAINT pack_plans_family_check CHECK (family IN ('VENDEUR', 'CONSOMMATEUR', 'PERSONNEL', 'MARCHAND'))");
        DB::statement("ALTER TABLE pack_plans ADD CONSTRAINT pack_plans_status_check CHECK (status IN ('draft', 'published', 'suspended', 'retired', 'archived'))");

        Schema::create('pack_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('pack_plan_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('price_xof');
            $table->char('currency', 3)->default('XOF');
            $table->string('period_unit', 10)->nullable();
            $table->unsignedSmallInteger('period_every')->nullable();
            $table->unsignedSmallInteger('period_cycles')->nullable();
            $table->decimal('quota_per_period', 28, 6)->nullable();
            $table->boolean('cumulative')->default(false);
            $table->string('end_of_term', 10)->nullable();
            $table->decimal('min_operation', 28, 6)->nullable();
            $table->decimal('max_operation', 28, 6)->nullable();
            $table->jsonb('eligible_person_types');
            $table->jsonb('rules');
            $table->text('reason');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['pack_plan_id', 'version']);
        });

        DB::statement("ALTER TABLE pack_versions ADD CONSTRAINT pack_versions_period_unit_check CHECK (period_unit IS NULL OR period_unit IN ('day', 'week', 'month'))");
        DB::statement("ALTER TABLE pack_versions ADD CONSTRAINT pack_versions_end_of_term_check CHECK (end_of_term IS NULL OR end_of_term IN ('expire', 'keep'))");
        DB::statement(<<<'SQL'
            ALTER TABLE pack_versions ADD CONSTRAINT pack_versions_periodic_check CHECK (
                (period_unit IS NULL AND period_every IS NULL AND period_cycles IS NULL AND quota_per_period IS NULL AND end_of_term IS NULL)
                OR (period_unit IS NOT NULL AND period_every >= 1 AND period_cycles >= 1 AND quota_per_period > 0 AND end_of_term IS NOT NULL)
            )
        SQL);

        // A published version is frozen: purchased packs keep their conditions whatever happens to the catalog.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pack_versions_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.published_at IS NOT NULL THEN
                        RAISE EXCEPTION 'A published pack version cannot be deleted' USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.published_at IS NOT NULL THEN
                    RAISE EXCEPTION 'A published pack version is immutable' USING ERRCODE = 'restrict_violation';
                END IF;

                IF (to_jsonb(NEW) - 'published_at') IS DISTINCT FROM (to_jsonb(OLD) - 'published_at') THEN
                    RAISE EXCEPTION 'Only the publication date of a pack version can change' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER pack_versions_guard_trigger
                BEFORE UPDATE OR DELETE ON pack_versions
                FOR EACH ROW EXECUTE FUNCTION pack_versions_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_versions');
        Schema::dropIfExists('pack_plans');
        DB::unprepared('DROP FUNCTION IF EXISTS pack_versions_guard();');
    }
};
