<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('exchange_requests', function (Blueprint $table) {
            // NULL means legacy terms: the snapshotted integer rate with no fee.
            $table->jsonb('pricing_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exchange_requests', function (Blueprint $table) {
            $table->dropColumn('pricing_snapshot');
        });
    }
};
