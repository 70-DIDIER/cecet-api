<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
});

/**
 * Creates a member, starts a payment and returns the attempt id.
 */
function memberWithAttempt(): string
{
    $member = User::factory()->status(UserStatus::PendingPayment)->create();

    return test()->withToken(tokenFor($member))
        ->postJson('/api/v1/me/membership/payments', [], ['Idempotency-Key' => (string) Str::ulid()])
        ->json('data.id');
}

it('lets accounting and administrators review payment attempts, filtered on those needing attention', function () {
    $clean = memberWithAttempt();
    $suspicious = memberWithAttempt();
    settleFakeAttempt($suspicious, ProviderPaymentStatus::Approved, amount: 5)->assertOk();

    $accounting = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();

    $this->withToken(tokenFor($accounting))->getJson('/api/v1/admin/payments/attempts?requires_review=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $suspicious)
        ->assertJsonPath('data.0.review_reason', 'amount_or_reference_mismatch');

    $this->withToken(tokenFor(User::factory()->staff(PermissionCatalog::ADMIN)->create()))
        ->getJson('/api/v1/admin/payments/attempts')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['id' => $clean]);
});

it('lists the webhooks received from providers', function () {
    settleFakeAttempt(memberWithAttempt(), ProviderPaymentStatus::Approved)->assertOk();

    $this->withToken(tokenFor(User::factory()->staff(PermissionCatalog::ACCOUNTING)->create()))
        ->getJson('/api/v1/admin/payments/webhook-events?status=processed&provider=fake')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event_name', 'transaction.approved');
});

it('hides payments from roles without the permission and from members', function () {
    $validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
    $member = User::factory()->create();

    $this->withToken(tokenFor($validator))->getJson('/api/v1/admin/payments/attempts')->assertForbidden();
    $this->withToken(tokenFor($member))->getJson('/api/v1/admin/payments/attempts')->assertForbidden()->assertJsonPath('code', 'STAFF_ONLY');
});

it('simulates a fake payment from the command line and prints the signed webhook', function () {
    $attemptId = memberWithAttempt();

    $this->artisan('payments:fake-settle', ['attempt' => $attemptId, 'status' => 'approved'])
        ->expectsOutputToContain('/api/v1/webhooks/payments/fake')
        ->expectsOutputToContain('x-fake-signature')
        ->assertSuccessful();

    $this->artisan('payments:fake-settle', ['attempt' => 'unknown'])->assertFailed();
});

it('refuses the simulation command in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('payments:fake-settle', ['attempt' => PaymentAttempt::query()->value('id') ?? 'x'])->assertFailed();
});
