<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only security journal: logins, OTP, role changes, revoked sessions, invalid webhooks.
     */
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestampTz('occurred_at', 6);
            $table->string('event_type', 80);
            $table->string('severity', 20);
            $table->ulid('user_id')->nullable();
            $table->string('identifier', 255)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->index(['event_type', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
            $table->index(['ip_address', 'occurred_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER security_events_append_only
                BEFORE UPDATE OR DELETE ON security_events
                FOR EACH ROW EXECUTE FUNCTION forbid_append_only_mutation();

            CREATE TRIGGER security_events_no_truncate
                BEFORE TRUNCATE ON security_events
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_append_only_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
