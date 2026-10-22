<?php

use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedCatalogs();
    Mail::fake();
});

it('reports true upfront payment amount including fees and never counts a pending pack as paid', function (): void {
    setFeeRules(['fake' => ['rate_bps' => 300, 'fixed_xof' => 0, 'rounding_xof' => 1]]);
    app(SettingsService::class)->set('payment.customer_bears_fees', true, 'Frais à la charge du membre');

    $member = User::factory()->create();
    $pack = makePlan(PackFamily::Consommateur, 'CS1', periodicTerms(['price_xof' => 100000]));
    $subscription = $this->withToken(tokenFor($member))
        ->postJson('/api/v1/me/packs', ['plan_id' => $pack->id], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated();
    $packId = $subscription->json('data.user_pack.id');
    $intent = PaymentIntent::query()->where('user_id', $member->id)->where('payable_id', $packId)->sole();

    $this->withToken(tokenFor($member))->getJson('/api/v1/me/payment-statement')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.payment_mode', 'upfront_once')
        ->assertJsonPath('data.installments_allowed', false)
        ->assertJsonPath('data.person_type', 'natural')
        ->assertJsonPath('data.summary.confirmed_payments_count', 0)
        ->assertJsonPath('data.summary.pending_payments_count', 1)
        ->assertJsonPath('data.summary.confirmed_total_xof', 0)
        ->assertJsonPath('data.payments.0.status', 'pending')
        ->assertJsonPath('data.payments.0.contract_amount_xof', 100000)
        ->assertJsonPath('data.payments.0.fee_amount_xof', $intent->fee_amount)
        ->assertJsonPath('data.payments.0.total_amount_xof', $intent->total_amount);

    settleFakeAttempt($subscription->json('data.payment.id'), ProviderPaymentStatus::Approved)->assertOk();
    $this->withToken(tokenFor($member))->getJson('/api/v1/me/payment-statement')->assertOk()
        ->assertJsonPath('data.summary.confirmed_payments_count', 1)
        ->assertJsonPath('data.summary.pending_payments_count', 0)
        ->assertJsonPath('data.summary.confirmed_total_xof', $intent->total_amount)
        ->assertJsonPath('data.summary.confirmed_contract_total_xof', 100000)
        ->assertJsonPath('data.summary.confirmed_fees_xof', $intent->fee_amount)
        ->assertJsonPath('data.payments.0.status', 'paid')
        ->assertJsonPath('data.payments.0.pack_id', $packId)
        ->assertJsonPath('data.payments.0.payment_intent_id', $intent->id);

    $other = User::factory()->create();
    $this->withToken(tokenFor($other))->getJson('/api/v1/me/payment-statement')->assertOk()
        ->assertJsonPath('data.payments', [])
        ->assertJsonPath('data.summary.confirmed_total_xof', 0);

});

it('supports PM Marchand and distinguishes a genuinely free pack from a paid settlement', function (): void {
    $legal = User::factory()->legalPerson()->create();
    $merchant = makePlan(PackFamily::Marchand, 'MS1', periodicTerms(['price_xof' => 90000]));
    $purchased = subscribeAndPay($legal, $merchant);

    $this->withToken(tokenFor($legal))->getJson('/api/v1/me/payment-statement')->assertOk()
        ->assertJsonPath('data.person_type', 'legal')
        ->assertJsonPath('data.payments.0.family', 'MARCHAND')
        ->assertJsonPath('data.payments.0.pack_id', $purchased->id)
        ->assertJsonPath('data.payments.0.status', 'paid');

    $natural = User::factory()->create();
    $free = makePlan(PackFamily::Consommateur, 'CF0', periodicTerms(['price_xof' => 0]));
    $this->withToken(tokenFor($natural))
        ->postJson('/api/v1/me/packs', ['plan_id' => $free->id], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()
        ->assertJsonPath('data.payment', null);

    $this->withToken(tokenFor($natural))->getJson('/api/v1/me/payment-statement')->assertOk()
        ->assertJsonPath('data.summary.confirmed_payments_count', 0)
        ->assertJsonPath('data.summary.confirmed_total_xof', 0)
        ->assertJsonPath('data.payments.0.status', 'free')
        ->assertJsonPath('data.payments.0.total_amount_xof', 0);
});

it('requires member authentication to read the statement', function (): void {
    $this->getJson('/api/v1/me/payment-statement')->assertUnauthorized();
});
