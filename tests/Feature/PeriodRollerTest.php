<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Enums\PeriodUnit;
use App\Domain\Quota\Exceptions\QuotaException;
use App\Domain\Quota\Models\QuotaLedgerEntry;
use App\Domain\Quota\QuotaLedger;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->ledger = app(QuotaLedger::class);
});

/**
 * @param  array<string, mixed>  $terms
 */
function activePack(array $terms = []): UserPack
{
    static $counter = 0;
    $counter++;

    return subscribeAndPay(User::factory()->create(), makePlan(code: "R{$counter}", terms: periodicTerms($terms)));
}

/**
 * @return array{0: string, 1: string, 2: string}
 */
function packBalance(UserPack $pack): array
{
    $account = $pack->account()->firstOrFail();

    return [$account->available, $account->reserved, $account->consumed];
}

function entryTypes(UserPack $pack): array
{
    return QuotaLedgerEntry::query()->where('user_pack_id', $pack->id)->orderBy('created_at')->orderBy('id')->pluck('type')->map(fn ($type) => $type->value)->all();
}

describe('periods', function () {
    it('generates contiguous periods covering the whole duration of the pack', function () {
        $pack = activePack(['period' => ['unit' => 'week', 'every' => 2, 'cycles' => 4]])->load('periods');

        expect($pack->periods)->toHaveCount(4);

        foreach ($pack->periods as $index => $period) {
            expect($period->number)->toBe($index + 1)
                ->and($period->quota)->toBe('125.000000');

            if ($index > 0) {
                expect($period->starts_at->equalTo($pack->periods[$index - 1]->ends_at))->toBeTrue();
            }
        }

        expect($pack->periods->first()->starts_at->equalTo($pack->activated_at))->toBeTrue()
            ->and($pack->periods->first()->ends_at->diffInDays($pack->periods->first()->starts_at, true))->toBe(14.0)
            ->and($pack->expires_at->equalTo($pack->periods->last()->ends_at))->toBeTrue()
            ->and($pack->periods->first()->credited_at)->not->toBeNull()
            ->and($pack->periods->last()->credited_at)->toBeNull();
    });

    it('computes month boundaries from the activation date without drifting', function () {
        $origin = CarbonImmutable::parse('2027-01-31 10:00:00');

        expect(PeriodUnit::Month->addTo($origin, 1)->toDateString())->toBe('2027-02-28')
            ->and(PeriodUnit::Month->addTo($origin, 2)->toDateString())->toBe('2027-03-31')
            ->and(PeriodUnit::Month->addTo($origin, 12)->toDateString())->toBe('2028-01-31')
            ->and(PeriodUnit::Week->addTo($origin, 2)->toDateString())->toBe('2027-02-14')
            ->and(PeriodUnit::Day->addTo($origin, 14)->toDateString())->toBe('2027-02-14');
    });
});

describe('rolling', function () {
    it('credits the next allowance when its period starts and accumulates it for a cumulative pack', function () {
        $pack = activePack(['cumulative' => true]);
        expect(packBalance($pack))->toBe(['125.000000', '0.000000', '0.000000']);

        $this->artisan('packs:roll-periods')->expectsOutputToContain('0 période(s)')->assertSuccessful();

        $this->travel(1)->month();
        $this->travel(1)->day();
        $this->artisan('packs:roll-periods')->expectsOutputToContain('1 période(s)')->assertSuccessful();

        expect(packBalance($pack))->toBe(['250.000000', '0.000000', '0.000000'])
            ->and(entryTypes($pack))->toBe(['credit', 'credit']);
    });

    it('expires the unused remainder of a non-cumulative pack but keeps what is reserved', function () {
        $pack = activePack(['cumulative' => false]);
        $this->ledger->reserve($pack, '40', 'op:roll:1');

        $this->travelTo(now()->addMonth()->addDay());
        $this->artisan('packs:roll-periods')->assertSuccessful();

        expect(packBalance($pack))->toBe(['125.000000', '40.000000', '0.000000'])
            ->and(entryTypes($pack))->toBe(['credit', 'reserve', 'expire', 'credit']);

        $expire = QuotaLedgerEntry::query()->where('user_pack_id', $pack->id)->where('type', 'expire')->sole();
        expect($expire->available_delta)->toBe('-85.000000')
            ->and($expire->pack_period_id)->not->toBeNull();

        $this->ledger->consume($pack, '40', 'op:roll:1:consume');
        expect(packBalance($pack))->toBe(['125.000000', '0.000000', '40.000000']);
    });

    it('catches up several late periods in order after an outage', function () {
        $pack = activePack(['cumulative' => false]);

        $this->travelTo(now()->addMonths(3)->addDay());
        $this->artisan('packs:roll-periods')->expectsOutputToContain('3 période(s)')->assertSuccessful();

        expect(packBalance($pack))->toBe(['125.000000', '0.000000', '0.000000'])
            ->and(entryTypes($pack))->toBe(['credit', 'expire', 'credit', 'expire', 'credit', 'expire', 'credit'])
            ->and($pack->periods()->whereNotNull('credited_at')->count())->toBe(4);
    });

    it('is idempotent: running it twice, or concurrently late, moves nothing more', function () {
        $pack = activePack(['cumulative' => true]);
        $this->travelTo(now()->addMonths(2)->addDay());

        $this->artisan('packs:roll-periods')->assertSuccessful();
        $entries = QuotaLedgerEntry::query()->count();
        $this->artisan('packs:roll-periods')->expectsOutputToContain('0 période(s)')->assertSuccessful();

        expect(QuotaLedgerEntry::query()->count())->toBe($entries)
            ->and(packBalance($pack))->toBe(['375.000000', '0.000000', '0.000000']);
        $this->artisan('ledger:verify')->assertSuccessful();
    });
});

describe('end of term', function () {
    it('expires the remainder and ends the pack when the policy is "expire"', function () {
        $pack = activePack(['period' => ['unit' => 'month', 'every' => 1, 'cycles' => 2], 'cumulative' => true, 'end_of_term' => 'expire']);
        $this->ledger->reserve($pack, '10', 'op:term:1');

        $this->travelTo(now()->addMonths(2)->addDay());
        $this->artisan('packs:roll-periods')->expectsOutputToContain('1 pack(s) arrivé(s) à terme')->assertSuccessful();

        expect($pack->refresh()->status)->toBe(UserPackStatus::Expired)
            ->and(packBalance($pack))->toBe(['0.000000', '10.000000', '0.000000'])
            ->and(entryTypes($pack))->toContain('expire')
            ->and(AuditEvent::query()->where('action', 'packs.expired')->sole()->metadata['end_of_term'])->toBe('expire');

        // An operation already in progress can still be settled after the pack ended.
        $this->ledger->consume($pack, '10', 'op:term:1:consume');
        expect(packBalance($pack))->toBe(['0.000000', '0.000000', '10.000000']);
        $this->artisan('ledger:verify')->assertSuccessful();
    });

    it('keeps the remainder usable until it is exhausted when the policy is "keep"', function () {
        $pack = activePack(['period' => ['unit' => 'month', 'every' => 1, 'cycles' => 2], 'cumulative' => true, 'end_of_term' => 'keep']);

        $this->travelTo(now()->addMonths(2)->addDay());
        $this->artisan('packs:roll-periods')->assertSuccessful();

        expect($pack->refresh()->status)->toBe(UserPackStatus::Active)
            ->and(packBalance($pack))->toBe(['250.000000', '0.000000', '0.000000']);

        $this->ledger->reserve($pack, '250', 'op:keep:1');
        $this->ledger->consume($pack, '250', 'op:keep:1:consume');
        $this->artisan('packs:roll-periods')->expectsOutputToContain('1 pack(s) arrivé(s) à terme')->assertSuccessful();

        expect($pack->refresh()->status)->toBe(UserPackStatus::Expired)
            ->and(packBalance($pack))->toBe(['0.000000', '0.000000', '250.000000']);
    });

    it('stops accepting new reservations once an "expire" pack reached its term, even before the roller runs', function () {
        $pack = activePack(['period' => ['unit' => 'month', 'every' => 1, 'cycles' => 1], 'end_of_term' => 'expire']);

        $this->travelTo(now()->addMonth()->addMinute());

        try {
            $this->ledger->reserve($pack, '1', 'op:late:1');
            test()->fail('The reservation should have been refused.');
        } catch (QuotaException $exception) {
            expect($exception->errorCode)->toBe('PACK_NOT_USABLE');
        }
    });
});
