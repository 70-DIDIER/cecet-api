<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Ops\Mail\OpsAlertMail;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    app(SettingsService::class)->set('ops.alert_emails', ['exploitation@cect.example'], 'Astreinte');
});

function deadOutboxMessage(): void
{
    DB::table('outbox_messages')->insert([
        'id' => (string) Str::ulid(),
        'type' => 'payout.dispatch',
        'payload' => json_encode(['payout_batch_id' => 'x']),
        'status' => 'dead',
        'attempts' => 10,
        'last_error' => 'Provider indisponible',
        'available_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('stays quiet when everything is healthy, integrity included', function () {
    subscribeAndPay(User::factory()->create(), makePlan());

    $this->artisan('ops:check --integrity')->expectsOutputToContain('OK      ledger_divergence')->assertSuccessful();

    Mail::assertNotQueued(OpsAlertMail::class);
});

it('raises a major alert once, records it and does not repeat it within the configured delay', function () {
    deadOutboxMessage();

    $this->artisan('ops:check')->expectsOutputToContain('outbox_dead')->assertFailed();
    $this->artisan('ops:check')->assertFailed();

    Mail::assertQueued(OpsAlertMail::class, 1);
    Mail::assertQueued(OpsAlertMail::class, fn (OpsAlertMail $mail) => $mail->hasTo('exploitation@cect.example') && $mail->code === 'outbox_dead' && $mail->severity === 'warning');
    expect(SecurityEvent::query()->where('event_type', 'ops.alert.outbox_dead')->count())->toBe(1);
});

it('treats a suspicious payment as critical and shows it on the health board', function () {
    $member = User::factory()->create();
    $response = $this->withToken(tokenFor($member))->postJson('/api/v1/me/packs', ['plan_id' => makePlan()->id], ['Idempotency-Key' => (string) Str::ulid()]);
    DB::table('payment_attempts')->where('id', $response->json('data.payment.id'))->update(['requires_review' => true, 'review_reason' => 'duplicate_payment', 'updated_at' => now()]);

    $this->artisan('ops:check')->assertFailed();
    Mail::assertQueued(OpsAlertMail::class, fn (OpsAlertMail $mail) => $mail->code === 'suspicious_payments' && $mail->envelope()->subject === '[CECT P1 CRITIQUE] suspicious_payments');

    $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    $this->withToken(tokenFor($superadmin))->getJson('/api/v1/admin/ops/health')
        ->assertOk()
        ->assertJsonPath('data.status', 'critical');
});

it('detects an altered ledger projection', function () {
    $pack = subscribeAndPay(User::factory()->create(), makePlan());
    DB::table('quota_accounts')->where('user_pack_id', $pack->id)->update(['available' => '9999']);

    $this->artisan('ops:check --integrity')->expectsOutputToContain('ledger_divergence')->assertFailed();

    Mail::assertQueued(OpsAlertMail::class, fn (OpsAlertMail $mail) => $mail->code === 'ledger_divergence' && $mail->severity === 'critical');
});

it('reserves the health board to the Superadmin', function () {
    $this->withToken(tokenFor(User::factory()->staff(PermissionCatalog::ADMIN)->create()))->getJson('/api/v1/admin/ops/health')->assertForbidden();
    $this->withToken(tokenFor(User::factory()->staff(PermissionCatalog::SUPERADMIN)->create()))->getJson('/api/v1/admin/ops/health')
        ->assertOk()
        ->assertJsonPath('data.status', 'ok');
});
