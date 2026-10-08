<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legal persons, distinct from the account of the person who represents them.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('legal_name', 200);
            $table->string('legal_form', 40);
            $table->string('rccm', 60)->nullable();
            $table->string('nif', 60)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
