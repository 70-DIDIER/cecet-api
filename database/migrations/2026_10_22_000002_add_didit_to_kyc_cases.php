<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_kyc_cases', function (Blueprint $table): void {
            $table->text('identity_payload')->nullable()->change();
            $table->string('document_front_path', 255)->nullable()->change();
            $table->timestampTz('submitted_at')->nullable()->change();
            $table->text('provider_session_url')->nullable();
            $table->string('provider_status', 32)->nullable()->index();
            $table->json('provider_checks')->nullable();
            $table->string('provider_environment', 12)->nullable();
            $table->string('provider_workflow_id', 128)->nullable();
            $table->timestampTz('provider_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('member_kyc_cases', function (Blueprint $table): void {
            $table->dropColumn(['provider_session_url','provider_status','provider_checks',
                'provider_environment','provider_workflow_id','provider_verified_at']);
        });
        // No destructive NOT NULL rollback: hosted Didit cases legitimately omit local documents.
    }
};
