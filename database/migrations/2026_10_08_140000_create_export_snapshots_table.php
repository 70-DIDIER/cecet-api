<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidated business snapshots (cahier v4.0 §10.1, AC-19): a versioned, hashed archive of the business data,
     * generated in the background after major mutations. It is a copy for offline use, never a source of truth.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS export_snapshot_version_seq START 1');

        Schema::create('export_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('version')->unique();
            $table->string('status', 20);
            $table->string('trigger', 20);
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('schema_version');
            $table->unsignedBigInteger('audit_position')->nullable();
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->jsonb('manifest')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('pruned_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'generated_at']);
        });

        DB::statement("ALTER TABLE export_snapshots ADD CONSTRAINT export_snapshots_status_check CHECK (status IN ('pending', 'ready', 'failed', 'pruned'))");
        DB::statement("ALTER TABLE export_snapshots ADD CONSTRAINT export_snapshots_trigger_check CHECK (trigger IN ('automatic', 'manual'))");
        DB::statement("ALTER TABLE export_snapshots ADD CONSTRAINT export_snapshots_ready_check CHECK (status <> 'ready' OR (sha256 IS NOT NULL AND path IS NOT NULL AND manifest IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('export_snapshots');
        DB::statement('DROP SEQUENCE IF EXISTS export_snapshot_version_seq');
    }
};
