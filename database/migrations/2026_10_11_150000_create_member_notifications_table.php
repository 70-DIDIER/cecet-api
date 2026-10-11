<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event', 100);
            $table->string('title', 180);
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['user_id', 'event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_notifications');
    }
};
