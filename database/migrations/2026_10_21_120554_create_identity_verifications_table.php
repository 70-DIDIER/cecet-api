<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('document_type', 30);
            $table->string('document_number', 60)->nullable();
            $table->string('front_path');
            $table->string('back_path')->nullable();
            $table->string('selfie_path');
            $table->string('status', 20);
            $table->text('rejection_reason')->nullable();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('identity_verified_at')->nullable()->after('email_verified_at');
        });

        // Members who joined before identity verification existed keep their access (decision of 2026-10-21).
        DB::table('users')->where('kind', 'member')->update(['identity_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('identity_verified_at');
        });

        Schema::dropIfExists('identity_verifications');
    }
};
