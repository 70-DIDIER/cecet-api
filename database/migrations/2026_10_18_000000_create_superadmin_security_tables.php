<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('superadmin_security_profiles', function (Blueprint $table) {
            $table->foreignUlid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('cert_sha256', 64)->unique();
            $table->string('email_1');
            $table->string('email_2');
            $table->string('email_3');
            $table->timestampsTz();
        });

        Schema::create('superadmin_access_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('cert_sha256', 64);
            $table->longText('public_key_pem');
            $table->string('status', 12)->index(); // pending, active, revoked
            $table->timestampTz('expires_at');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
        });

        Schema::create('superadmin_security_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('cert_sha256', 64);
            $table->string('purpose', 24)->index();
            $table->uuid('key_id')->nullable();
            $table->json('email_addresses')->nullable();
            $table->json('code_hashes')->nullable();
            $table->string('nonce', 128)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('superadmin_security_challenges');
        Schema::dropIfExists('superadmin_access_keys');
        Schema::dropIfExists('superadmin_security_profiles');
    }
};
