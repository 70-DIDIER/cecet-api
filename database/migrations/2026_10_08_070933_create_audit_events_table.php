<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only business audit journal, tamper-evident through a SHA-256 hash chain
     * computed by the database itself (position + prev_hash + hash).
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('position')->unique();
            $table->timestampTz('occurred_at', 6);
            $table->string('actor_type', 20);
            $table->ulid('actor_id')->nullable();
            $table->string('action', 120);
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('result', 20);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->text('reason')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);

            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index('correlation_id');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_event_hash(e audit_events, previous_hash text) RETURNS text
            LANGUAGE sql IMMUTABLE AS $$
                SELECT encode(sha256(convert_to(concat_ws('|',
                    coalesce(previous_hash, ''),
                    e.id,
                    e.position::text,
                    to_char(e.occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US'),
                    e.actor_type,
                    coalesce(e.actor_id, ''),
                    e.action,
                    coalesce(e.subject_type, ''),
                    coalesce(e.subject_id, ''),
                    e.result,
                    coalesce(e.before::text, ''),
                    coalesce(e.after::text, ''),
                    coalesce(e.metadata::text, ''),
                    coalesce(e.reason, ''),
                    coalesce(e.correlation_id, ''),
                    coalesce(e.ip_address, ''),
                    coalesce(e.user_agent, '')
                ), 'UTF8')), 'hex');
            $$;

            CREATE OR REPLACE FUNCTION audit_events_chain() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                last_position bigint;
                last_hash text;
            BEGIN
                -- Serializes chain extension until the end of the inserting transaction.
                PERFORM pg_advisory_xact_lock(hashtext('audit_events_chain'));

                SELECT position, hash INTO last_position, last_hash
                FROM audit_events ORDER BY position DESC LIMIT 1;

                NEW.position := coalesce(last_position, 0) + 1;
                NEW.prev_hash := last_hash;
                NEW.hash := audit_event_hash(NEW, last_hash);

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER audit_events_chain_before_insert
                BEFORE INSERT ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_chain();

            CREATE TRIGGER audit_events_append_only
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION forbid_append_only_mutation();

            CREATE TRIGGER audit_events_no_truncate
                BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_append_only_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS audit_events_chain() CASCADE;');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_event_hash(audit_events, text);');
        Schema::dropIfExists('audit_events');
    }
};
