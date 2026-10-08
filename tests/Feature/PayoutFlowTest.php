<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Exchange\Actions\SellerExchangeService;
use App\Domain\Exchange\Actions\ValidationDesk;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Payout\Enums\PayoutItemStatus;
use App\Domain\Payout\Enums\ProviderPayoutStatus;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Payout\Models\PayoutItem;
use App\Domain\Payout\Providers\Fake\FakePayoutProvider;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Outbox\Outbox;
use App\Domain\Shared\Outbox\OutboxHandler;
use App\Domain\Shared\Outbox\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    configureCectPernum();
    $this->settings = app(SettingsService::class);
    $this->fake = app(FakePayoutProvider::class);
    $this->validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
    $this->accounting = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();
});

/**
 * Sells $quantity YEM at 500 F, has them confirmed, and returns the payout batch created by the confirmation.
 */
function confirmedSale(string $quantity, string $method = 'TMONEY'): PayoutBatch
{
    [$member, $pack] = readySeller(['quota_per_period' => '5000'], $method);
    $exchange = app(SellerExchangeService::class)->create($member, $pack->load('version'), $quantity);
    app(ValidationDesk::class)->confirm(test()->validator, $exchange, $quantity, 'YEM-TX', null);

    return PayoutBatch::query()->where('payable_id', $exchange->id)->sole();
}

function financePost(User $user, string $uri, array $payload = []): TestResponse
{
    return test()->withToken(tokenFor($user, withStepUp: true))->postJson("/api/v1/finance/{$uri}", $payload, ['Idempotency-Key' => (string) Str::ulid()]);
}

describe('routing', function () {
    it('splits Mobile Money transfers under the unit ceiling and sends large amounts to a manual settlement', function (string $quantity, string $method, string $route, string $status, array $items) {
        $batch = confirmedSale($quantity, $method);

        expect($batch->route->value)->toBe($route)
            ->and($batch->status->value)->toBe($status)
            ->and($batch->items()->pluck('amount')->all())->toBe($items)
            ->and($batch->paid_amount + $batch->remaining_amount)->toBe($batch->total_amount)
            ->and($batch->reference)->toMatch('/^PO-\d{7}$/');
    })->with([
        '500 000 F: one transfer' => ['1000', 'TMONEY', 'mobile_money', 'awaiting_dispatch', [500000]],
        '750 000 F: two transfers' => ['1500', 'FLOOZ', 'mobile_money', 'awaiting_dispatch', [500000, 250000]],
        '1 200 000 F: three transfers' => ['2400', 'TMONEY', 'mobile_money', 'awaiting_dispatch', [500000, 500000, 200000]],
        '1 600 000 F: manual settlement' => ['3200', 'TMONEY', 'manual', 'manual_required', []],
        'cash chosen by the member' => ['40', 'CASH', 'manual', 'manual_required', []],
    ]);

    it('follows the configured ceilings', function () {
        $this->settings->set('payout.mobile_money.unit_max_xof', 200000, 'Plafond opérateur');
        $this->settings->set('payout.mobile_money.max_items', 2, 'Deux envois au plus');

        expect(confirmedSale('800')->items()->pluck('amount')->all())->toBe([200000, 200000])
            ->and(confirmedSale('801')->route->value)->toBe('manual');
    });
});

describe('sending', function () {
    it('sends automatically after the confirmation when enabled, then completes the exchange (AC-15)', function () {
        $this->settings->set('payout.auto_enabled', true, 'Décaissement automatique activé');

        $batch = confirmedSale('1500');

        expect($batch->refresh()->status->value)->toBe('paid')
            ->and($batch->paid_amount)->toBe(750000)
            ->and(collect($this->fake->sent())->pluck('amount')->all())->toBe([500000, 250000])
            ->and(ExchangeRequest::query()->findOrFail($batch->payable_id)->status->value)->toBe('completed')
            ->and(OutboxMessage::query()->sole()->status)->toBe('processed');
    });

    it('waits for Accounting when automatic payouts are disabled', function () {
        $batch = confirmedSale('40');
        expect($this->fake->sent())->toBe([]);

        financePost($this->accounting, "payouts/{$batch->id}/dispatch")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.operation.yem_confirmed', true);

        financePost($this->accounting, "payouts/{$batch->id}/dispatch")->assertStatus(409)->assertJsonPath('code', 'PAYOUT_NOT_DISPATCHABLE');
        expect($this->fake->sent())->toHaveCount(1);
    });

    it('retries only the failed transfers and never resends a paid one (AC-12, AC-16)', function () {
        $this->fake->script(['succeeded', 'failed', 'succeeded']);
        $batch = confirmedSale('2400');
        $pack = ExchangeRequest::query()->findOrFail($batch->payable_id)->userPack;

        financePost($this->accounting, "payouts/{$batch->id}/dispatch")
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.paid_amount', 700000)
            ->assertJsonPath('data.remaining_amount', 500000);

        // The quota stays consumed although part of the counter-value failed.
        expect($pack->account()->first()->consumed)->toBe('2400.000000')
            ->and(ExchangeRequest::query()->findOrFail($batch->payable_id)->status->value)->toBe('yem_received');

        financePost($this->accounting, "payouts/{$batch->id}/retry", ['reason' => 'Numéro corrigé par l\'opérateur'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $references = collect($this->fake->sent())->pluck('merchant_reference')->all();
        expect($references)->toHaveCount(4)
            ->and(array_slice($references, -1))->toBe(["{$batch->reference}-2-2"])
            ->and(PayoutItem::query()->where('payout_batch_id', $batch->id)->where('status', PayoutItemStatus::Succeeded)->count())->toBe(3);

        financePost($this->accounting, "payouts/{$batch->id}/retry", ['reason' => 'Rien à relancer'])->assertStatus(409)->assertJsonPath('code', 'PAYOUT_NOTHING_TO_RETRY');
    });

    it('recovers a lost response through the merchant reference instead of sending twice', function () {
        $this->fake->script(['lost']);
        $batch = confirmedSale('40');

        financePost($this->accounting, "payouts/{$batch->id}/dispatch")->assertJsonPath('data.status', 'processing');
        $item = PayoutItem::query()->where('payout_batch_id', $batch->id)->sole();
        expect($item->status)->toBe(PayoutItemStatus::Processing)->and($item->provider_reference)->toBeNull();

        $this->artisan('payouts:refresh')->assertSuccessful();
        $item->refresh();
        expect($item->provider_reference)->not->toBeNull()->and($item->status)->toBe(PayoutItemStatus::Processing);

        $this->fake->settle((string) $item->provider_reference, ProviderPayoutStatus::Succeeded);
        $this->artisan('payouts:refresh')->expectsOutputToContain('1 virement(s)')->assertSuccessful();

        expect($batch->refresh()->status->value)->toBe('paid')
            ->and($this->fake->sent())->toHaveCount(1);
    });

    it('declares failed a transfer the provider never received, so it can be retried', function () {
        $batch = confirmedSale('40');
        $item = PayoutItem::query()->where('payout_batch_id', $batch->id)->sole();
        $item->forceFill(['status' => PayoutItemStatus::Processing, 'provider' => 'fake', 'merchant_reference' => 'PO-unknown-1-1', 'submitted_at' => now()])->save();

        $this->artisan('payouts:refresh')->assertSuccessful();
        expect($item->refresh()->status)->toBe(PayoutItemStatus::Processing);

        $this->travel(31)->minutes();
        $this->artisan('payouts:refresh')->assertSuccessful();
        expect($item->refresh()->status)->toBe(PayoutItemStatus::Failed)->and($item->last_error)->toBe('not_found_at_provider');
    });
});

describe('fees (PO-09)', function () {
    it('sends the full amount when the cooperative bears the fees', function () {
        $this->settings->set('payout.provider_fees', ['fake' => ['rate_bps' => 100, 'fixed_xof' => 0]], 'Barème du contrat');
        $batch = confirmedSale('1000');

        financePost($this->accounting, "payouts/{$batch->id}/dispatch")->assertJsonPath('data.items.0.transfer_amount', 500000)->assertJsonPath('data.items.0.fee_amount', 5000);
    });

    it('reduces the transfer when the beneficiary bears the fees', function () {
        $this->settings->set('payout.fees_borne_by', 'beneficiary', 'Frais à la charge du bénéficiaire');
        $this->settings->set('payout.provider_fees', ['fake' => ['rate_bps' => 100, 'fixed_xof' => 0]], 'Barème du contrat');
        $batch = confirmedSale('1000');

        financePost($this->accounting, "payouts/{$batch->id}/dispatch")
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paid_amount', 500000)
            ->assertJsonPath('data.items.0.transfer_amount', 495049)
            ->assertJsonPath('data.items.0.fee_amount', 4951);
    });

    it('blocks instead of guessing when the beneficiary bears fees that are not configured', function () {
        $this->settings->set('payout.fees_borne_by', 'beneficiary', 'Frais à la charge du bénéficiaire');
        $this->settings->set('payout.provider_fees', ['fedapay' => ['rate_bps' => 100, 'fixed_xof' => 0]], 'Barème FedaPay seulement');
        $batch = confirmedSale('40');

        financePost($this->accounting, "payouts/{$batch->id}/dispatch")
            ->assertJsonPath('data.status', 'awaiting_dispatch')
            ->assertJsonPath('data.blocked_reason', 'fees_not_configured');

        expect($this->fake->sent())->toBe([]);
    });
});

describe('manual settlements', function () {
    it('applies a small cash settlement immediately', function () {
        $batch = confirmedSale('40', 'CASH');

        financePost($this->accounting, "payouts/{$batch->id}/manual-settlements", ['method' => 'CASH', 'amount' => 20000, 'reference' => 'REÇU-0001', 'paid_at' => now()->toIso8601String()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'applied')
            ->assertJsonPath('data.payout.status', 'paid');

        expect(ExchangeRequest::query()->findOrFail($batch->payable_id)->status->value)->toBe('completed');
    });

    it('requires a second person above the threshold (maker-checker)', function () {
        $batch = confirmedSale('3200');
        $settlementId = financePost($this->accounting, "payouts/{$batch->id}/manual-settlements", ['method' => 'BANK_TRANSFER', 'amount' => 1600000, 'reference' => 'VIR-2026-001', 'paid_at' => now()->toIso8601String()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.payout.status', 'manual_required')
            ->json('data.settlement_id');

        financePost($this->accounting, "manual-settlements/{$settlementId}/approve")->assertForbidden()->assertJsonPath('code', 'MAKER_CHECKER_VIOLATION');
        expect(SecurityEvent::query()->where('event_type', 'finance.maker_checker_violation')->exists())->toBeTrue();

        $this->withToken(tokenFor($this->accounting))->getJson('/api/v1/finance/manual-settlements')->assertJsonCount(1, 'data');

        $checker = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();
        financePost($checker, "manual-settlements/{$settlementId}/approve")->assertOk()->assertJsonPath('data.status', 'applied');

        expect($batch->refresh()->status->value)->toBe('paid');
    });

    it('refuses a settlement that does not match the remaining amount or races a transfer in progress', function () {
        $batch = confirmedSale('40');

        financePost($this->accounting, "payouts/{$batch->id}/manual-settlements", ['method' => 'CASH', 'amount' => 19999, 'reference' => 'R', 'paid_at' => now()->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('code', 'SETTLEMENT_AMOUNT_MISMATCH');

        PayoutItem::query()->where('payout_batch_id', $batch->id)->update(['status' => 'processing']);
        financePost($this->accounting, "payouts/{$batch->id}/manual-settlements", ['method' => 'CASH', 'amount' => 20000, 'reference' => 'R', 'paid_at' => now()->toIso8601String()])
            ->assertStatus(409)->assertJsonPath('code', 'PAYOUT_TRANSFER_IN_PROGRESS');
    });

    it('lets a rejected settlement be recorded again correctly', function () {
        $batch = confirmedSale('3200');
        $first = financePost($this->accounting, "payouts/{$batch->id}/manual-settlements", ['method' => 'BANK_TRANSFER', 'amount' => 1600000, 'reference' => 'VIR-ERR', 'paid_at' => now()->toIso8601String()])->json('data.settlement_id');

        financePost(User::factory()->staff(PermissionCatalog::ACCOUNTING)->create(), "manual-settlements/{$first}/reject", ['reason' => 'Référence de virement erronée'])->assertJsonPath('data.status', 'rejected');
        financePost($this->accounting, "payouts/{$batch->id}/manual-settlements", ['method' => 'BANK_TRANSFER', 'amount' => 1600000, 'reference' => 'VIR-OK', 'paid_at' => now()->toIso8601String()])->assertJsonPath('data.status', 'pending_approval');
    });
});

describe('accounting view', function () {
    it('shows the YEM confirmation read-only and is closed to other roles (AC-14)', function () {
        $batch = confirmedSale('40');

        $this->withToken(tokenFor($this->accounting))->getJson('/api/v1/finance/payouts')
            ->assertOk()
            ->assertJsonPath('data.0.reference', $batch->reference)
            ->assertJsonPath('data.0.operation.yem_confirmed', true)
            ->assertJsonPath('data.0.method', 'TMONEY');

        $this->withToken(tokenFor($this->validator))->getJson('/api/v1/finance/payouts')->assertForbidden();
        financePost($this->validator, "payouts/{$batch->id}/dispatch")->assertForbidden();
    });
});

describe('outbox', function () {
    it('retries a failing message with backoff and parks it as dead after too many attempts', function () {
        $outbox = app(Outbox::class);
        $outbox->register('test.failing', FailingOutboxHandler::class);
        $message = $outbox->push('test.failing', ['x' => 1]);

        expect($message->refresh()->attempts)->toBe(1)->and($message->status)->toBe('pending');

        foreach (range(2, 10) as $attempt) {
            $this->travel(2)->hours();
            $outbox->relay();
        }

        expect($message->refresh()->status)->toBe('dead')
            ->and($message->attempts)->toBe(10)
            ->and(SecurityEvent::query()->where('event_type', 'outbox.message_dead')->exists())->toBeTrue();
    });
});

class FailingOutboxHandler implements OutboxHandler
{
    public function handle(array $payload): void
    {
        throw new RuntimeException('Simulated failure');
    }
}
