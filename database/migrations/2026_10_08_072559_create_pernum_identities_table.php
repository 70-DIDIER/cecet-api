<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pernum identifiers of the external YEM ecosystem declared by members.
     * A Pernum can only belong to one non-revoked identity at a time.
     */
    public function up(): void
    {
        Schema::create('pernum_identities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('pernum', 64);
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
        });

        DB::statement("ALTER TABLE pernum_identities ADD CONSTRAINT pernum_identities_kind_check CHECK (kind IN ('personal', 'business'))");
        DB::statement("CREATE UNIQUE INDEX pernum_identities_active_pernum_unique ON pernum_identities (pernum) WHERE status <> 'revoked'");
    }

    public function down(): void
    {
        Schema::dropIfExists('pernum_identities');
    }
};
