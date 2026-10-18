<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Quota\Exceptions\QuotaException;
use App\Domain\Quota\Models\QuotaLedgerEntry;
use App\Domain\Quota\QuotaLedger;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->ledger = app(QuotaLedger::class);
    $this->member = User::factory()->create();
    $this->pack = subscribeAndPay($this->member, makePlan());
});

/**
 * @return array{0: string, 1: string, 2: string} available, reserved, consumed
 */
function balanceOf(UserPack $pack): array
{
    $account = $pack->account()->firstOrFail();

    return [$account->available, $account->reserved, $account->consumed];
}

function expectQuotaError(Closure $operation, string $code): void
{
    try {
        $operation();
    } catch (QuotaException $exception) {
        expect($exception->errorCode)->toBe($code);

        return;
    }

    test()->fail("Expected QuotaException {$code}.");
}

/**
 * Runs a statement that must be rejected by the database, inside a savepoint so the test can go on.
 */
function expectDatabaseRejection(Closure $statement): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class);
}

describe('movements', function () {
    it('moves quota between available, reserved and consumed with exact arithmetic', function () {
        expect(balanceOf($this->pack))->toBe(['125.000000', '0.000000', '0.000000']);

        $this->ledger->reserve($this->pack, '40', 'op:1:reserve');
        expect(balanceOf($this->pack))->toBe(['85.000000', '40.000000', '0.000000']);

        $this->ledger->consume($this->pack, '25', 'op:1:consume');
        expect(balanceOf($this->pack))->toBe(['85.000000', '15.000000', '25.000000']);

        $this->ledger->release($this->pack, '15', 'op:1:release');
        expect(balanceOf($this->pack))->toBe(['100.000000', '0.000000', '25.000000']);

        $entries = QuotaLedgerEntry::query()->where('user_pack_id', $this->pack->id)->orderBy('id')->get();
        expect($entries->pluck('type.value')->all())->toBe(['credit', 'reserve', 'consume', 'release'])
            ->and($entries->last()->available_after)->toBe('100.000000')
            ->and($entries->last()->consumed_after)->toBe('25.000000');
    });

    it('never loses a fraction of YEM', function () {
        $this->ledger->reserve($this->pack, '0.000001', 'op:tiny:reserve');
        $this->ledger->reserve($this->pack, '124.999999', 'op:rest:reserve');

        expect(balanceOf($this->pack))->toBe(['0.000000', '125.000000', '0.000000']);
        expectQuotaError(fn () => $this->ledger->reserve($this->pack, '0.000001', 'op:over:reserve'), 'INSUFFICIENT_QUOTA');
    });

    it('refuses to reserve more than what is available and writes nothing', function () {
        $entries = QuotaLedgerEntry::query()->count();

        expectQuotaError(fn () => $this->ledger->reserve($this->pack, '125.000001', 'op:2:reserve'), 'INSUFFICIENT_QUOTA');

        expect(balanceOf($this->pack))->toBe(['125.000000', '0.000000', '0.000000'])
            ->and(QuotaLedgerEntry::query()->count())->toBe($entries);
    });

    it('refuses to release or consume more than what is reserved', function () {
        $this->ledger->reserve($this->pack, '10', 'op:3:reserve');

        expectQuotaError(fn () => $this->ledger->consume($this->pack, '10.5', 'op:3:consume'), 'INSUFFICIENT_RESERVED_QUOTA');
        expectQuotaError(fn () => $this->ledger->release($this->pack, '11', 'op:3:release'), 'INSUFFICIENT_RESERVED_QUOTA');
        expect(balanceOf($this->pack))->toBe(['115.000000', '10.000000', '0.000000']);
    });

    it('rejects invalid quantities', function (string $quantity) {
        expectQuotaError(fn () => $this->ledger->reserve($this->pack, $quantity, 'op:bad:'.Str::random(6)), 'INVALID_QUANTITY');
    })->with(['zero' => '0', 'negative' => '-5', 'too many decimals' => '1.1234567', 'text' => 'abc', 'empty' => '', 'exponent' => '1e3']);

    it('keeps reservations and consumption possible after the pack stopped accepting new ones', function () {
        $this->ledger->reserve($this->pack, '30', 'op:4:reserve');
        $this->pack->forceFill(['status' => UserPackStatus::Expired])->save();

        expectQuotaError(fn () => $this->ledger->reserve($this->pack, '1', 'op:4:another'), 'PACK_NOT_USABLE');

        $this->ledger->consume($this->pack, '20', 'op:4:consume');
        $this->ledger->release($this->pack, '10', 'op:4:release');
        expect(balanceOf($this->pack))->toBe(['105.000000', '0.000000', '20.000000']);
    });

    it('applies the operation limits of the purchased version when reserving', function () {
        $pack = subscribeAndPay(User::factory()->create(), makePlan(code: 'V2', terms: periodicTerms(['min_operation' => '5', 'max_operation' => '50'])));

        expectQuotaError(fn () => $this->ledger->reserve($pack, '4.999999', 'op:min'), 'OPERATION_OUT_OF_LIMITS');
        expectQuotaError(fn () => $this->ledger->reserve($pack, '50.000001', 'op:max'), 'OPERATION_OUT_OF_LIMITS');
        $this->ledger->reserve($pack, '5', 'op:ok:1');
        $this->ledger->reserve($pack, '50', 'op:ok:2');

        expect(balanceOf($pack))->toBe(['70.000000', '55.000000', '0.000000']);
    });
});

describe('idempotency', function () {
    it('replays the original entry without moving quota twice', function () {
        $first = $this->ledger->reserve($this->pack, '40', 'op:5:reserve');
        $second = $this->ledger->reserve($this->pack, '40', 'op:5:reserve');

        expect($second->id)->toBe($first->id)
            ->and(balanceOf($this->pack))->toBe(['85.000000', '40.000000', '0.000000'])
            ->and(QuotaLedgerEntry::query()->where('idempotency_key', 'op:5:reserve')->count())->toBe(1);
    });

    it('refuses a key reused for a different operation', function (Closure $reuse) {
        $this->ledger->reserve($this->pack, '40', 'op:6:reserve');
        $other = subscribeAndPay(User::factory()->create(), makePlan(code: 'V3'));

        expectQuotaError(fn () => $reuse($this->ledger, $this->pack, $other), 'LEDGER_IDEMPOTENCY_CONFLICT');
        expect(balanceOf($this->pack))->toBe(['85.000000', '40.000000', '0.000000']);
    })->with([
        'other quantity' => [fn (QuotaLedger $ledger, UserPack $pack) => $ledger->reserve($pack, '41', 'op:6:reserve')],
        'other type' => [fn (QuotaLedger $ledger, UserPack $pack) => $ledger->release($pack, '40', 'op:6:reserve')],
        'other pack' => [fn (QuotaLedger $ledger, UserPack $pack, UserPack $other) => $ledger->reserve($other, '40', 'op:6:reserve')],
    ]);
});

describe('database guarantees', function () {
    it('refuses a negative balance in the account projection', function () {
        expectDatabaseRejection(fn () => DB::table('quota_accounts')->where('user_pack_id', $this->pack->id)->update(['available' => '-0.000001']));
        expectDatabaseRejection(fn () => DB::table('quota_accounts')->where('user_pack_id', $this->pack->id)->update(['reserved' => '-1']));
    });

    it('refuses ledger entries whose shape does not match their type', function (array $overrides) {
        $row = [
            'id' => (string) Str::ulid(), 'user_pack_id' => $this->pack->id, 'type' => 'credit',
            'available_delta' => '1', 'reserved_delta' => '0', 'consumed_delta' => '0',
            'available_after' => '1', 'reserved_after' => '0', 'consumed_after' => '0',
            'idempotency_key' => 'raw:'.Str::random(8), 'created_at' => now(),
        ];

        expectDatabaseRejection(fn () => DB::table('quota_ledger_entries')->insert([...$row, ...$overrides]));
    })->with([
        'credit that also reserves' => [['reserved_delta' => '1']],
        'reserve that creates quota' => [['type' => 'reserve', 'available_delta' => '1', 'reserved_delta' => '1']],
        'reserve without matching reserved' => [['type' => 'reserve', 'available_delta' => '-5', 'reserved_delta' => '4']],
        'consume that touches available' => [['type' => 'consume', 'available_delta' => '1', 'reserved_delta' => '-1', 'consumed_delta' => '1']],
        'zero adjustment' => [['type' => 'adjust', 'available_delta' => '0']],
        'unknown type' => [['type' => 'bonus']],
    ]);

    it('makes ledger entries append-only', function () {
        $entry = QuotaLedgerEntry::query()->firstOrFail();

        expectDatabaseRejection(fn () => DB::table('quota_ledger_entries')->where('id', $entry->id)->update(['reason' => 'réécrit']));
        expectDatabaseRejection(fn () => DB::table('quota_ledger_entries')->where('id', $entry->id)->delete());
        expectDatabaseRejection(fn () => DB::statement('truncate quota_ledger_entries'));
        expect(fn () => $entry->forceFill(['reason' => 'x'])->save())->toThrow(LogicException::class);
    });

    it('verifies that every account matches its entries, and detects a divergence', function () {
        $this->ledger->reserve($this->pack, '10', 'op:7:reserve');
        $this->artisan('ledger:verify')->expectsOutputToContain('cohérent')->assertSuccessful();

        DB::table('quota_accounts')->where('user_pack_id', $this->pack->id)->update(['available' => '999']);

        $this->artisan('ledger:verify')->expectsOutputToContain($this->pack->id)->assertFailed();
    });
});

describe('manual adjustments', function () {
    it('applies an audited correction and never goes below zero', function () {
        $actor = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();

        $entry = $this->ledger->adjust($this->pack, '-25', 'adjust:1', $actor, 'Erreur de saisie lors du crédit initial');
        expect($entry->actor_id)->toBe($actor->id)
            ->and($entry->reason)->toBe('Erreur de saisie lors du crédit initial')
            ->and(balanceOf($this->pack))->toBe(['100.000000', '0.000000', '0.000000']);

        $this->ledger->adjust($this->pack, '10.5', 'adjust:2', $actor, 'Régularisation');
        expectQuotaError(fn () => $this->ledger->adjust($this->pack, '-1000', 'adjust:3', $actor, 'Trop'), 'INSUFFICIENT_QUOTA');
        expectQuotaError(fn () => $this->ledger->adjust($this->pack, '0', 'adjust:4', $actor, 'Rien'), 'INVALID_QUANTITY');
        expect(balanceOf($this->pack))->toBe(['110.500000', '0.000000', '0.000000']);
    });

    it('lets only the Superadmin adjust through the API, with step-up, reason and idempotency', function () {
        $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
        $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
        $uri = "/api/v1/admin/user-packs/{$this->pack->id}/adjustments";
        $payload = ['quantity' => '-5.5', 'reason' => 'Correction après contrôle du Validateur'];
        $key = ['Idempotency-Key' => 'adjust-api-0001'];

        $this->withToken(tokenFor($admin, withStepUp: true))->postJson($uri, $payload, $key)->assertForbidden();
        $this->withToken(tokenFor($superadmin))->postJson($uri, $payload, $key)->assertForbidden()->assertJsonPath('code', 'STEP_UP_REQUIRED');
        $this->withToken(tokenFor($superadmin, withStepUp: true))->postJson($uri, $payload)->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->withToken(tokenFor($superadmin, withStepUp: true))->postJson($uri, [...$payload, 'reason' => 'court'], $key)->assertStatus(422);

        $this->withToken(tokenFor($superadmin, withStepUp: true))->postJson($uri, $payload, $key)
            ->assertCreated()
            ->assertJsonPath('data.available_after', '119.500000');

        $this->withToken(tokenFor($superadmin, withStepUp: true))->postJson($uri, $payload, $key)->assertCreated();

        expect(balanceOf($this->pack))->toBe(['119.500000', '0.000000', '0.000000'])
            ->and(AuditEvent::query()->where('action', 'quota.adjusted')->count())->toBe(1);
    });

    it('lets administrators review member packs with their recent movements', function () {
        $this->ledger->reserve($this->pack, '10', 'op:8:reserve');
        $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();

        $this->withToken(tokenFor($admin))->getJson("/api/v1/admin/user-packs?user_id={$this->member->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.balance.reserved', '10.000000');

        $this->withToken(tokenFor($admin))->getJson("/api/v1/admin/user-packs/{$this->pack->id}")
            ->assertOk()
            ->assertJsonPath('data.owner_id', $this->member->id)
            ->assertJsonCount(2, 'data.ledger');

        $this->withToken(tokenFor(User::factory()->staff()->create()))->getJson('/api/v1/admin/user-packs')->assertForbidden();
    });
});

describe('Personnel pack (on demand, no fixed quota)', function () {
    beforeEach(function () {
        $this->personnel = subscribeAndPay($this->member, makePlan(PackFamily::Personnel, 'P1'));
    });

    it('has no period, no expiry and starts with an empty balance (AC-08)', function () {
        $pack = $this->personnel->load('periods');

        expect($pack->status)->toBe(UserPackStatus::Active)
            ->and($pack->periods)->toHaveCount(0)
            ->and($pack->expires_at)->toBeNull()
            ->and(balanceOf($pack))->toBe(['0.000000', '0.000000', '0.000000'])
            ->and(QuotaLedgerEntry::query()->where('user_pack_id', $pack->id)->count())->toBe(0);
    });

    it('allows purchase and consumption on demand as long as the tracked balance is sufficient', function () {
        expectQuotaError(fn () => $this->ledger->reserve($this->personnel, '1', 'p:empty'), 'INSUFFICIENT_QUOTA');

        $this->ledger->credit($this->personnel, '30', 'p:purchase:1', reason: 'Achat de YEM à la demande');
        $this->ledger->reserve($this->personnel, '20', 'p:pay:1:reserve');
        expectQuotaError(fn () => $this->ledger->reserve($this->personnel, '10.000001', 'p:pay:2:reserve'), 'INSUFFICIENT_QUOTA');
        $this->ledger->consume($this->personnel, '20', 'p:pay:1:consume');

        expect(balanceOf($this->personnel))->toBe(['10.000000', '0.000000', '20.000000']);
    });

    it('is never touched by the period roller, even years later', function () {
        $this->ledger->credit($this->personnel, '30', 'p:purchase:2');

        $this->travel(10)->years();
        $this->artisan('packs:roll-periods')->assertSuccessful();

        expect($this->personnel->refresh()->status)->toBe(UserPackStatus::Active)
            ->and(balanceOf($this->personnel))->toBe(['30.000000', '0.000000', '0.000000']);
    });
});
