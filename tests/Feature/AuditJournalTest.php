<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Audit\SensitiveDataRedactor;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('records the acting user, subject and redacted payloads', function () {
    $actor = User::factory()->create();
    $subject = User::factory()->create();

    $event = app(AuditLogger::class)->record(
        action: 'members.updated',
        subject: $subject,
        before: ['email' => 'old@example.com', 'password' => 'secret-1'],
        after: ['email' => 'new@example.com', 'nested' => ['api_token' => 'abc']],
        reason: 'Correction demandée par le membre',
        actor: $actor,
    );

    expect($event)
        ->actor_type->toBe(AuditEvent::ACTOR_USER)
        ->actor_id->toBe($actor->id)
        ->subject_id->toBe($subject->id)
        ->and($event->before['password'])->toBe(SensitiveDataRedactor::MASK)
        ->and($event->after['nested']['api_token'])->toBe(SensitiveDataRedactor::MASK)
        ->and($event->after['email'])->toBe('new@example.com');
});

it('records system actions when nobody is authenticated', function () {
    $event = app(AuditLogger::class)->record('settings.initialized');

    expect($event->actor_type)->toBe(AuditEvent::ACTOR_SYSTEM)
        ->and($event->actor_id)->toBeNull();
});

it('chains each event to the previous one', function () {
    $logger = app(AuditLogger::class);

    $first = $logger->record('first.action');
    $second = $logger->record('second.action');

    expect($second->position)->toBe($first->position + 1)
        ->and($second->prev_hash)->toBe($first->hash)
        ->and($second->hash)->toHaveLength(64);

    $this->artisan('audit:verify')->assertSuccessful();
});

it('refuses updates and deletions at the database level', function (string $statement) {
    app(AuditLogger::class)->record('immutable.action');

    expect(fn () => DB::statement($statement))->toThrow(QueryException::class);
})->with([
    'update' => ["update audit_events set action = 'tampered'"],
    'delete' => ['delete from audit_events'],
    'truncate' => ['truncate audit_events'],
]);

it('detects a tampered event', function () {
    $logger = app(AuditLogger::class);
    $logger->record('first.action');
    $tampered = $logger->record('second.action');
    $logger->record('third.action');

    DB::statement('alter table audit_events disable trigger audit_events_append_only');
    DB::table('audit_events')->where('id', $tampered->id)->update(['action' => 'forged.action']);
    DB::statement('alter table audit_events enable trigger audit_events_append_only');

    $this->artisan('audit:verify')
        ->expectsOutputToContain("rompue à la position {$tampered->position}")
        ->assertFailed();
});

it('writes append-only security events with redacted metadata', function () {
    $event = app(SecurityEventLogger::class)->record(
        eventType: 'auth.login_failed',
        severity: SecuritySeverity::Warning,
        identifier: 'someone@example.com',
        metadata: ['otp' => '123456', 'attempt' => 3],
    );

    expect($event->metadata)->toBe(['otp' => SensitiveDataRedactor::MASK, 'attempt' => 3]);
    expect(fn () => DB::statement('delete from security_events'))->toThrow(QueryException::class);
});
