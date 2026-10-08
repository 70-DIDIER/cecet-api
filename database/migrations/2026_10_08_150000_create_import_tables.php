<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Controlled re-import of a consolidated file (cahier v4.0 §10.2, AC-20): never a direct overwrite. The file is
     * staged, checked (schema, hash, identifiers), diffed against the snapshot it came from and the current data,
     * approved by a second authorised person, then applied atomically and audited.
     */
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('dataset', 30);
            $table->string('status', 20);
            $table->foreignUlid('base_snapshot_id')->constrained('export_snapshots')->restrictOnDelete();
            $table->string('file_name', 255);
            $table->char('sha256', 64);
            $table->string('path', 255);
            $table->jsonb('summary');
            $table->foreignUlid('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE import_jobs ADD CONSTRAINT import_jobs_status_check CHECK (status IN ('staged', 'applied', 'canceled'))");
        DB::statement("CREATE UNIQUE INDEX import_jobs_applied_file_unique ON import_jobs (sha256) WHERE status = 'applied'");

        Schema::create('import_rows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('import_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line');
            $table->string('user_id', 26)->nullable();
            $table->string('status', 20);
            $table->jsonb('changes')->nullable();
            $table->jsonb('errors')->nullable();

            $table->index(['import_job_id', 'status']);
        });

        DB::statement("ALTER TABLE import_rows ADD CONSTRAINT import_rows_status_check CHECK (status IN ('unchanged', 'change', 'conflict', 'error'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_jobs');
    }
};
