<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_guardian_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('staff_access_windows', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->json('weekdays')->nullable();
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->string('timezone', 60)->default('Africa/Lome');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_access_windows');
        Schema::dropIfExists('staff_guardian_challenges');
    }
};
