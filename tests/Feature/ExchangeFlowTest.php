<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Exchange\Models\ValidationEvent;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Payout\Models\PayoutDestination;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    configureCectPernum();
    [$this->member, $this->pack] = readySeller();
    $this->validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
});

function sell(User $member, UserPack $pack, string $quantity = '40'): TestResponse
{
    return test()->withToken(tokenFor($member))
        ->postJson('/api/v1/me/exchange-requests', ['user_pack_id' => $pack->id, 'quantity' => $quantity], ['Idempotency-Key' => (string) Str::ulid()]);
}

function confirmReceipt(User $validator, string $exchangeId, string $quantity, ?string $reference = 'YEM-TX-0001'): TestResponse
{
    return test()->withToken(tokenFor($validator, withStepUp: true))
        ->postJson("/api/v1/validations/{$exchangeId}/confirm", array_filter(['quantity_received' => $quantity, 'external_reference' => $reference], fn ($value) => $value !== null), ['Idempotency-Key' => (string) Str::ulid()]);
}

/**
 * @return array{0: string, 1: string, 2: string}
 */
function sellerBalance(UserPack $pack): array
{
    $account = $pack->account()->firstOrFail();

    return [$account->available, $account->reserved, $account->consumed];
}

describe('member request', function () {
    it('stores the selected country, network and payout number and enforces the configured network countries', function () {
        $token = tokenFor($this->member, withStepUp: true);

        $this->withToken($token)->putJson('/api/v1/me/payout-destination', [
            'method' => 'TMONEY',
            'country' => 'TG',
            'phone' => '+22890123456',
        ])
            ->assertOk()
            ->assertJsonPath('data.method', 'TMONEY')
            ->assertJsonPath('data.country', 'TG')
            ->assertJsonPath('data.phone', '+22890123456');

        $this->withToken($token)->putJson('/api/v1/me/payout-destination', [
            'method' => 'TMONEY',
            'country' => 'BJ',
            'phone' => '+22966000001',
        ])
            ->assertStatus(422)
            ->assertJsonPath('details.fields.country.0', 'Ce moyen de versement n’est pas disponible dans le pays sélectionné.');
    });

    it('reserves the quantity and gives the CECT Pernum to send the YEM to (AC-09)', function () {
        sell($this->member, $this->pack)
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_transfer')
            ->assertJsonPath('data.yem_confirmed', false)
            ->assertJsonPath('data.quantity_requested', '40.000000')
            ->assertJsonPath('data.rate_xof_per_yem', 500)
            ->assertJsonPath('data.estimated_amount_xof', 20000)
            ->assertJsonPath('data.amount_due_xof', null)
            ->assertJsonPath('data.transfer_instructions.send_to_pernum', '1000000001')
            ->assertJsonPath('data.payout_destination.method', 'TMONEY');

        expect(sellerBalance($this->pack))->toBe(['85.000000', '40.000000', '0.000000'])
            ->and(ExchangeRequest::query()->sole()->reference)->toMatch('/^EX-\d{7}$/');
    });

    it('refuses what the pack or the setup does not allow, without reserving anything', function (Closure $setup, string $code, int $status) {
        $pack = $setup($this);

        sell($this->member, $pack ?? $this->pack, $this->quantity ?? '40')
            ->assertStatus($status)
            ->assertJsonPath('code', $code);

        expect(ExchangeRequest::query()->count())->toBe(0)
            ->and(sellerBalance($this->pack))->toBe(['125.000000', '0.000000', '0.000000']);
    })->with([
        'more than the available quota' => [function ($test) {
            $test->quantity = '125.000001';
        }, 'INSUFFICIENT_QUOTA', 422],
        'no payout destination' => [function ($test) {
            PayoutDestination::query()->delete();
        }, 'PAYOUT_DESTINATION_REQUIRED', 422],
        'CECT Pernum not configured' => [function ($test) {
            app(SettingsService::class)->set('yem.cect_pernum', null, 'Pernum retiré pour maintenance', now()->addSecond());
            test()->travel(2)->seconds();
        }, 'CECT_PERNUM_NOT_CONFIGURED', 503],
        'a non-Vendeur pack' => [fn ($test) => subscribeAndPay($test->member, makePlan(PackFamily::Personnel, 'P1')), 'PACK_NOT_ELIGIBLE_FOR_EXCHANGE', 422],
    ]);

    it('hides the pack and the requests of other members', function () {
        [$stranger, $theirPack] = readySeller();
        $theirs = sell($stranger, $theirPack)->json('data.id');

        sell($this->member, $theirPack)->assertNotFound();
        $this->withToken(tokenFor($this->member))->getJson("/api/v1/me/exchange-requests/{$theirs}")->assertNotFound();
        $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/exchange-requests/{$theirs}/cancel")->assertNotFound();
    });

    it('treats "I sent it" as information only: nothing moves and nothing becomes payable (AC-10)', function () {
        $id = sell($this->member, $this->pack)->json('data.id');

        $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/exchange-requests/{$id}/declare-sent", ['transfer_reference' => 'TX-42'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declared_sent')
            ->assertJsonPath('data.member_transfer_reference', 'TX-42')
            ->assertJsonPath('data.yem_confirmed', false);

        expect(sellerBalance($this->pack))->toBe(['85.000000', '40.000000', '0.000000'])
            ->and(PayoutBatch::query()->count())->toBe(0);
    });

    it('cancels before the transfer is declared, and only then', function () {
        $first = sell($this->member, $this->pack)->json('data.id');
        $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/exchange-requests/{$first}/cancel")->assertOk()->assertJsonPath('data.status', 'canceled');
        expect(sellerBalance($this->pack))->toBe(['125.000000', '0.000000', '0.000000']);

        $second = sell($this->member, $this->pack)->json('data.id');
        $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/exchange-requests/{$second}/declare-sent")->assertOk();
        $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/exchange-requests/{$second}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('code', 'EXCHANGE_NOT_CANCELABLE');
    });

    it('expires a request left without transfer and gives its quota back, but never a declared one', function () {
        $silent = sell($this->member, $this->pack, '30')->json('data.id');
        $declared = sell($this->member, $this->pack, '20')->json('data.id');
        $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/exchange-requests/{$declared}/declare-sent")->assertOk();

        $this->travel(1441)->minutes();
        $this->artisan('exchanges:expire')->expectsOutputToContain('1 demande(s)')->assertSuccessful();

        expect(ExchangeRequest::query()->findOrFail($silent)->status->value)->toBe('expired')
            ->and(ExchangeRequest::query()->findOrFail($declared)->status->value)->toBe('declared_sent')
            ->and(sellerBalance($this->pack))->toBe(['105.000000', '20.000000', '0.000000']);
    });
});

describe('validation', function () {
    it('lists declared transfers and disputes for the Validator', function () {
        $awaiting = sell($this->member, $this->pack, '10')->json('data.id');
        $declared = sell($this->member, $this->pack, '20')->json('data.id');
        $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/exchange-requests/{$declared}/declare-sent")->assertOk();

        $this->withToken(tokenFor($this->validator))->getJson('/api/v1/validations/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $declared);

        $this->withToken(tokenFor($this->validator))->getJson('/api/v1/validations/pending?status[]=awaiting_transfer')
            ->assertJsonPath('data.0.id', $awaiting);
    });

    it('confirms the YEM received: consumes the quota, computes the debt with the snapshotted rate and creates the payout', function () {
        $id = sell($this->member, $this->pack)->json('data.id');
        app(SettingsService::class)->set('rates.purchase_xof_per_yem', 520, 'Nouveau cours après la demande');

        confirmReceipt($this->validator, $id, '40')
            ->assertOk()
            ->assertJsonPath('data.status', 'yem_received')
            ->assertJsonPath('data.yem_confirmed', true)
            ->assertJsonPath('data.quantity_received', '40.000000')
            ->assertJsonPath('data.amount_due_xof', 20000)
            ->assertJsonPath('data.payout.total_amount', 20000)
            ->assertJsonPath('data.payout.status', 'awaiting_dispatch')
            ->assertJsonPath('data.validation_events.0.action', 'confirmed')
            ->assertJsonPath('data.validation_events.0.external_reference', 'YEM-TX-0001');

        expect(sellerBalance($this->pack))->toBe(['85.000000', '0.000000', '40.000000'])
            ->and(ExchangeRequest::query()->sole()->validated_by)->toBe($this->validator->id)
            ->and(AuditEvent::query()->where('action', 'exchanges.yem_received')->sole()->after['amount_due_xof'])->toBe(20000);
    });

    it('gives back the part that never arrived', function () {
        $id = sell($this->member, $this->pack)->json('data.id');

        confirmReceipt($this->validator, $id, '30.5')
            ->assertOk()
            ->assertJsonPath('data.amount_due_xof', 15250);

        expect(sellerBalance($this->pack))->toBe(['94.500000', '0.000000', '30.500000']);
    });

    it('applies the rate chosen by the pack version', function () {
        [$member, $pack] = readySeller(['rules' => ['rate_setting_key' => 'rates.transfer_xof_per_yem']]);
        $id = sell($member, $pack, '10')->assertJsonPath('data.rate_xof_per_yem', 610)->json('data.id');

        confirmReceipt($this->validator, $id, '10')->assertJsonPath('data.amount_due_xof', 6100);
    });

    it('refuses an impossible confirmation', function (string $quantity, ?string $reference, string $code) {
        $id = sell($this->member, $this->pack)->json('data.id');

        confirmReceipt($this->validator, $id, $quantity, $reference)->assertStatus(422)->assertJsonPath('code', $code);

        expect(sellerBalance($this->pack))->toBe(['85.000000', '40.000000', '0.000000']);
    })->with([
        'more than requested' => ['40.000001', 'YEM-TX', 'RECEIVED_EXCEEDS_REQUESTED'],
        'nothing received' => ['0', 'YEM-TX', 'INVALID_QUANTITY'],
        'no YEM transaction reference' => ['40', null, 'EXTERNAL_REFERENCE_REQUIRED'],
    ]);

    it('rejects a transfer that cannot be found and gives the quota back', function () {
        $id = sell($this->member, $this->pack)->json('data.id');

        $this->withToken(tokenFor($this->validator, withStepUp: true))->postJson("/api/v1/validations/{$id}/reject", ['reason' => 'Aucun transfert trouvé sur le Pernum CECT'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        expect(sellerBalance($this->pack))->toBe(['125.000000', '0.000000', '0.000000'])
            ->and(PayoutBatch::query()->count())->toBe(0);
    });

    it('keeps a disputed request reserved until the Validator decides', function () {
        $id = sell($this->member, $this->pack)->json('data.id');

        $this->withToken(tokenFor($this->validator, withStepUp: true))->postJson("/api/v1/validations/{$id}/dispute", ['comment' => 'Montant reçu à vérifier avec YEMPay'])
            ->assertOk()
            ->assertJsonPath('data.status', 'disputed');
        expect(sellerBalance($this->pack))->toBe(['85.000000', '40.000000', '0.000000']);

        confirmReceipt($this->validator, $id, '40')->assertOk()->assertJsonPath('data.status', 'yem_received');
        expect(ValidationEvent::query()->where('exchange_request_id', $id)->pluck('action')->map->value->all())->toBe(['disputed', 'confirmed']);
    });

    it('never decides twice on the same request', function () {
        $id = sell($this->member, $this->pack)->json('data.id');
        confirmReceipt($this->validator, $id, '40')->assertOk();

        confirmReceipt($this->validator, $id, '40')->assertStatus(409)->assertJsonPath('code', 'EXCHANGE_NOT_OPEN');
        expect(PayoutBatch::query()->count())->toBe(1);
    });

    it('lets only the Validator confirm, with a step-up (AC-11, AC-14)', function () {
        $id = sell($this->member, $this->pack)->json('data.id');
        $accounting = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();
        $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();

        confirmReceipt($accounting, $id, '40')->assertForbidden();
        confirmReceipt($admin, $id, '40')->assertForbidden();
        $this->withToken(tokenFor($this->validator))->postJson("/api/v1/validations/{$id}/confirm", ['quantity_received' => '40', 'external_reference' => 'X'], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertForbidden()
            ->assertJsonPath('code', 'STEP_UP_REQUIRED');
        $this->withToken(tokenFor($this->member))->getJson('/api/v1/validations/pending')->assertForbidden();

        expect(ExchangeRequest::query()->sole()->status->value)->toBe('awaiting_transfer');
    });
});

it('refuses to sell a zero quantity cleanly', function () {
    [$member, $pack] = readySeller();

    sell($member, $pack, '0')->assertStatus(422)->assertJsonPath('code', 'INVALID_QUANTITY');
    sell($member, $pack, '0.000000')->assertStatus(422)->assertJsonPath('code', 'INVALID_QUANTITY');
});
