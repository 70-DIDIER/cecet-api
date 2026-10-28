<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_kyc_cases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status', 24)->default('submitted')->index();
            $table->string('verification_mode', 16)->default('manual');
            $table->text('identity_payload'); // encrypted at rest by Laravel
            $table->string('document_front_path', 255);
            $table->string('document_back_path', 255)->nullable();
            $table->string('company_document_path', 255)->nullable();
            $table->boolean('privacy_notice_accepted')->default(false);
            $table->timestampTz('submitted_at');
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_reason', 1000)->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('provider_reference', 128)->nullable()->unique();
            $table->timestampTz('provider_reviewed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_kyc_cases');
    }
};
