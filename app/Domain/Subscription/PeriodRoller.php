<?php

namespace App\Domain\Subscription;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\EndOfTermPolicy;
use App\Domain\Quota\Models\QuotaAccount;
use App\Domain\Quota\QuotaLedger;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Domain\Subscription\Models\PackPeriod;
use App\Domain\Subscription\Models\UserPack;
use App\Support\YemQuantity;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves periodic packs through their cycles: credits each allowance when its period starts, expires the unused
 * remainder of a period when the pack is not cumulative, and applies the end-of-term policy.
 *
 * Idempotent: every ledger movement has a deterministic key, so running it twice (or concurrently) is harmless.
 */
class PeriodRoller
{
    public function __construct(
        private readonly QuotaLedger $ledger,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Processes every pack that has something due.
     *
     * @return array{credited: int, expired_packs: int, failures: int}
     */
    public function run(CarbonInterface $at): array
    {
        $credited = 0;
        $expiredPacks = 0;
        $failures = 0;

        UserPack::query()
            ->where('status', UserPackStatus::Active)
            ->whereNotNull('expires_at')
            ->where(function ($query) use ($at): void {
                $query->where('expires_at', '<=', $at)
                    ->orWhereExists(fn ($due) => $due->selectRaw('1')
                        ->from('pack_periods')
                        ->whereColumn('pack_periods.user_pack_id', 'user_packs.id')
                        ->whereNull('pack_periods.credited_at')
                        ->where('pack_periods.starts_at', '<=', $at));
            })
            ->chunkById(100, function ($packs) use ($at, &$credited, &$expiredPacks, &$failures): void {
                foreach ($packs as $pack) {
                    try {
                        $credited += $this->creditDuePeriods($pack, $at);

                        if ($pack->refresh()->status === UserPackStatus::Expired) {
                            $expiredPacks++;
                        }
                    } catch (Throwable $exception) {
                        $failures++;
                        report($exception);
                    }
                }
            });

        return ['credited' => $credited, 'expired_packs' => $expiredPacks, 'failures' => $failures];
    }

    /**
     * Credits the periods of a pack that have started, then closes the term if it has ended.
     */
    public function creditDuePeriods(UserPack $pack, CarbonInterface $at): int
    {
        $credited = 0;

        $duePeriods = PackPeriod::query()
            ->where('user_pack_id', $pack->id)
            ->whereNull('credited_at')
            ->where('starts_at', '<=', $at)
            ->orderBy('number')
            ->get();

        foreach ($duePeriods as $period) {
            $this->creditPeriod($pack, $period, $at);
            $credited++;
        }

        $this->closeTermIfEnded($pack, $at);

        return $credited;
    }

    private function creditPeriod(UserPack $pack, PackPeriod $period, CarbonInterface $at): void
    {
        $pack->loadMissing('version');

        DB::transaction(function () use ($pack, $period, $at): void {
            $account = QuotaAccount::query()->lockForUpdate()->findOrFail($pack->id);
            $period = PackPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($period->credited_at !== null) {
                return;
            }

            $previous = PackPeriod::query()->where('user_pack_id', $pack->id)->where('number', $period->number - 1)->first();

            if ($previous !== null && $previous->closed_at === null) {
                if (! $pack->version->cumulative && YemQuantity::isPositive($account->available)) {
                    $this->ledger->expire($pack, $account->available, "period:{$previous->id}:expire", $previous, 'Fin de période : quota non cumulable non utilisé');
                }

                $previous->forceFill(['closed_at' => $at])->save();
            }

            $this->ledger->credit($pack, $period->quota, "period:{$period->id}:credit", $period);
            $period->forceFill(['credited_at' => $at])->save();
        });
    }

    private function closeTermIfEnded(UserPack $pack, CarbonInterface $at): void
    {
        DB::transaction(function () use ($pack, $at): void {
            $pack = UserPack::query()->with('version')->lockForUpdate()->findOrFail($pack->id);

            if ($pack->status !== UserPackStatus::Active || $pack->expires_at === null || $pack->expires_at->greaterThan($at)) {
                return;
            }

            $account = QuotaAccount::query()->lockForUpdate()->findOrFail($pack->id);

            PackPeriod::query()->where('user_pack_id', $pack->id)->whereNull('closed_at')->update(['closed_at' => $at]);

            if ($pack->version->end_of_term === EndOfTermPolicy::Expire) {
                if (YemQuantity::isPositive($account->available)) {
                    $this->ledger->expire($pack, $account->available, "term:{$pack->id}:expire", reason: 'Fin de durée du pack : quota non utilisé expiré');
                }

                $this->expirePack($pack, $at, 'expire');

                return;
            }

            // "keep": the remainder stays usable until it is exhausted, then the pack ends.
            if (YemQuantity::isZero($account->available) && YemQuantity::isZero($account->reserved)) {
                $this->expirePack($pack, $at, 'keep');
            }
        });
    }

    private function expirePack(UserPack $pack, CarbonInterface $at, string $policy): void
    {
        $pack->forceFill(['status' => UserPackStatus::Expired])->save();

        $this->auditLogger->record(
            action: 'packs.expired',
            subject: $pack,
            before: ['status' => UserPackStatus::Active->value],
            after: ['status' => UserPackStatus::Expired->value],
            metadata: ['end_of_term' => $policy, 'at' => $at->toIso8601ZuluString()],
        );
    }
}
