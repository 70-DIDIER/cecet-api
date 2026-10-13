<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Notification\Mail\MemberNoticeMail;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->validator = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();
});

function noticesTo(User $user, string $event): int
{
    return Mail::queued(MemberNoticeMail::class, fn (MemberNoticeMail $mail) => $mail->event === $event && $mail->hasTo($user->email))->count();
}

it('tells the member their membership is active once the cash payment is validated', function () {
    $member = User::factory()->status(UserStatus::PendingPayment)->create();
    $attemptId = $this->withToken(tokenFor($member))
        ->postJson('/api/v1/me/membership/payments', ['method' => 'CASH'], ['Idempotency-Key' => (string) Str::ulid()])
        ->json('data.id');

    $this->withToken(tokenFor($this->validator, withStepUp: true))
        ->postJson("/api/v1/validations/payments/{$attemptId}/approve", ['received_amount' => 25000, 'reference' => 'Reçu 7'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    expect(noticesTo($member, 'membership.activated'))->toBe(1);

    $mail = Mail::queued(MemberNoticeMail::class)->first();
    expect($mail->subjectLine)->toBe('Votre adhésion à la CECT est active')
        ->and($mail->render())->toContain('Bonjour '.$member->full_name);
});

it('tells the member why a declared payment was rejected', function () {
    $member = User::factory()->status(UserStatus::PendingPayment)->create();
    $attemptId = $this->withToken(tokenFor($member))
        ->postJson('/api/v1/me/membership/payments', ['method' => 'CASH'], ['Idempotency-Key' => (string) Str::ulid()])
        ->json('data.id');

    $this->withToken(tokenFor($this->validator, withStepUp: true))
        ->postJson("/api/v1/validations/payments/{$attemptId}/reject", ['reason' => 'Aucun versement à ce nom'])
        ->assertOk();

    $mail = Mail::queued(MemberNoticeMail::class, fn (MemberNoticeMail $mail) => $mail->event === 'payment.rejected')->sole();
    expect($mail->hasTo($member->email))->toBeTrue()
        ->and($mail->render())->toContain('Aucun versement à ce nom')->toContain('adhésion');
});

it('notifies the pack activation and the merchant of a payment to confirm, then the payer of the confirmation', function () {
    $merchant = User::factory()->legalPerson()->create();
    $merchant->pernumIdentities()->create(['pernum' => '1001292934', 'kind' => 'business', 'status' => 'verified']);
    $merchantPack = subscribeAndPay($merchant, makePlan(PackFamily::Marchand, 'BRONZE', periodicTerms(['eligible_person_types' => ['legal']])));

    $payer = User::factory()->create();
    $consumer = subscribeAndPay($payer, makePlan(PackFamily::Consommateur, 'C1'));

    expect(noticesTo($payer, 'pack.activated'))->toBe(1)
        ->and(noticesTo($merchant, 'pack.activated'))->toBe(1);

    $token = $this->withToken(tokenFor($merchant))->postJson('/api/v1/merchant/qr-codes', ['user_pack_id' => $merchantPack->id], ['Idempotency-Key' => (string) Str::ulid()])->json('data.code');
    $id = $this->withToken(tokenFor($payer))->postJson('/api/v1/merchant-payments/intents', ['token' => $token, 'user_pack_id' => $consumer->id, 'quantity' => '10'], ['Idempotency-Key' => (string) Str::ulid()])->json('data.id');
    $this->withToken(tokenFor($payer))->postJson("/api/v1/me/merchant-payments/{$id}/declare-sent")->assertOk();

    expect(noticesTo($merchant, 'merchant_payment.declared'))->toBe(1);

    $this->withToken(tokenFor($merchant))->postJson("/api/v1/merchant/payments/{$id}/confirm", [], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();

    expect(noticesTo($payer, 'merchant_payment.confirmed'))->toBe(1);
});

it('sends nothing for an event switched off by the Superadmin', function () {
    app(SettingsService::class)->set('notifications.disabled_events', ['pack.activated'], 'Trop de courriels');

    $member = User::factory()->create();
    subscribeAndPay($member, makePlan());

    expect(noticesTo($member, 'pack.activated'))->toBe(0);
});
