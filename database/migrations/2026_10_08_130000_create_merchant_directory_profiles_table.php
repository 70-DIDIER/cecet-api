<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Public directory of merchants accepting YEM. Opt-in: a merchant only appears once it chose to be listed,
     * with the information it chose to publish.
     */
    public function up(): void
    {
        Schema::create('merchant_directory_profiles', function (Blueprint $table) {
            $table->foreignUlid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('listed')->default(false);
            $table->string('display_name', 160);
            $table->string('activity', 160)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('public_phone', 30)->nullable();
            $table->text('description')->nullable();
            $table->timestampTz('listed_at')->nullable();
            $table->timestampsTz();

            $table->index(['listed', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_directory_profiles');
    }
};
