<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned configuration engine.
     * "settings" mirrors the code catalog (definitions); "setting_versions" is the append-only value history.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->string('group', 60)->index();
            $table->string('type', 20);
            $table->text('description');
            $table->jsonb('rules');
            $table->jsonb('default_value')->nullable();
            $table->string('edit_level', 20);
            $table->boolean('is_sensitive')->default(false);
            $table->jsonb('production_forbidden_values')->nullable();
            $table->timestampsTz();
        });

        Schema::create('setting_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('setting_key', 120);
            $table->jsonb('value')->nullable();
            $table->timestampTz('effective_from', 6);
            $table->ulid('created_by')->nullable();
            $table->text('reason');
            $table->timestampTz('created_at', 6);

            $table->foreign('setting_key')->references('key')->on('settings')->restrictOnDelete();
            $table->unique(['setting_key', 'effective_from']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER setting_versions_append_only
                BEFORE UPDATE OR DELETE ON setting_versions
                FOR EACH ROW EXECUTE FUNCTION forbid_append_only_mutation();

            CREATE TRIGGER setting_versions_no_truncate
                BEFORE TRUNCATE ON setting_versions
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_append_only_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_versions');
        Schema::dropIfExists('settings');
    }
};
