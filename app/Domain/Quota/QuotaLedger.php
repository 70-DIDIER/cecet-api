<?php

namespace App\Domain\Quota;

use App\Domain\Catalog\Enums\EndOfTermPolicy;
use App\Domain\Quota\Enums\LedgerEntryType;
use App\Domain\Quota\Exceptions\QuotaException;
use App\Domain\Quota\Models\QuotaAccount;
use App\Domain\Quota\Models\QuotaLedgerEntry;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Domain\Subscription\Models\PackPeriod;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use App\Support\YemQuantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of quota movements.
 *
 * Every operation runs in one transaction that locks the pack's account row (SELECT ... FOR UPDATE), checks the
 * invariant on exact decimals, inserts the immutable entry and updates the projection. Two concurrent
 * reservations of the same quota are therefore serialized and the second one sees the first one's effect.
 *
 * Every operation carries an idempotency key: replaying it returns the original entry without moving anything,
 * and reusing it for a different operation is refused.
 */
class QuotaLedger
{
    /**
     * Grants quota (period allowance). available += quantity.
     */
    public function credit(UserPack $pack, string $quantity, string $idempotencyKey, ?PackPeriod $period = null, ?string $reason = null): QuotaLedgerEntry
    {
        return $this->apply($pack, LedgerEntryType::Credit, $quantity, $idempotencyKey, period: $period, reason: $reason);
    }

    /**
     * Grants the YEM offered as cashback, on its own entry so that it is never mixed with the YEM bought.
     */
    public function creditCashback(UserPack $pack, string $quantity, string $idempotencyKey, ?PackPeriod $period = null, ?string $reason = null): QuotaLedgerEntry
    {
        return $this->apply($pack, LedgerEntryType::CashbackCredit, $quantity, $idempotencyKey, period: $period, reason: $reason);
    }

    /**
     * Sets quota aside for an open operation. available -= quantity, reserved += quantity.
     */
    public function reserve(UserPack $pack, string $quantity, string $idempotencyKey, ?Model $source = null): QuotaLedgerEntry
    {
        return $this->apply($pack, LedgerEntryType::Reserve, $quantity, $idempotencyKey, source: $source);
    }

    /**
     * Gives a reservation back (cancellation, expiry of the operation). reserved -= quantity, available += quantity.
     */
    public function release(UserPack $pack, string $quantity, string $idempotencyKey, ?Model $source = null, ?string $reason = null): QuotaLedgerEntry
    {
        return $this->apply($pack, LedgerEntryType::Release, $quantity, $idempotencyKey, source: $source, reason: $reason);
    }

    /**
     * Turns a reservation into definitive consumption (confirmed operation). reserved -= quantity, consumed += quantity.
     */
    public function consume(UserPack $pack, string $quantity, string $idempotencyKey, ?Model $source = null): QuotaLedgerEntry
    {
        return $this->apply($pack, LedgerEntryType::Consume, $quantity, $idempotencyKey, source: $source);
    }

    /**
     * Removes unused quota (end of period without carry-over, end of term). available -= quantity.
     */
    public function expire(UserPack $pack, string $quantity, string $idempotencyKey, ?PackPeriod $period = null, ?string $reason = null): QuotaLedgerEntry
    {
        return $this->apply($pack, LedgerEntryType::Expire, $quantity, $idempotencyKey, period: $period, reason: $reason);
    }

    /**
     * Audited manual correction, positive or negative, never below zero. Requires a reason and an author.
     */
    public function adjust(UserPack $pack, string $signedQuantity, string $idempotencyKey, User $actor, string $reason): QuotaLedgerEntry
    {
        return $this->apply($pack, LedgerEntryType::Adjust, $signedQuantity, $idempotencyKey, reason: $reason, actor: $actor);
    }

    private function apply(
        UserPack $pack,
        LedgerEntryType $type,
        string $quantity,
        string $idempotencyKey,
        ?Model $source = null,
        ?PackPeriod $period = null,
        ?string $reason = null,
        ?User $actor = null,
    ): QuotaLedgerEntry {
        $quantity = $this->normalize($type, $quantity);

        try {
            return DB::transaction(function () use ($pack, $type, $quantity, $idempotencyKey, $source, $period, $reason, $actor): QuotaLedgerEntry {
                $account = QuotaAccount::query()->lockForUpdate()->find($pack->id) ?? throw QuotaException::accountMissing();

                $existing = QuotaLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $this->replay($existing, $pack, $type, $quantity);
                }

                $deltas = $this->deltas($type, $quantity);

                if ($type === LedgerEntryType::Reserve) {
                    $this->assertReservable($pack, $account, $quantity);
                }

                $after = [
                    'available' => YemQuantity::add($account->available, $deltas['available']),
                    'reserved' => YemQuantity::add($account->reserved, $deltas['reserved']),
                    'consumed' => YemQuantity::add($account->consumed, $deltas['consumed']),
                ];

                $this->assertNonNegative($type, $account, $after, $quantity);

                $entry = QuotaLedgerEntry::query()->create([
                    'user_pack_id' => $pack->id,
                    'type' => $type,
                    'available_delta' => $deltas['available'],
                    'reserved_delta' => $deltas['reserved'],
                    'consumed_delta' => $deltas['consumed'],
                    'available_after' => $after['available'],
                    'reserved_after' => $after['reserved'],
                    'consumed_after' => $after['consumed'],
                    'idempotency_key' => $idempotencyKey,
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source !== null ? (string) $source->getKey() : null,
                    'pack_period_id' => $period?->id,
                    'reason' => $reason,
                    'actor_id' => $actor?->id,
                    'correlation_id' => Context::get('correlation_id'),
                    'created_at' => now(),
                ]);

                $account->forceFill([
                    'available' => $after['available'],
                    'reserved' => $after['reserved'],
                    'consumed' => $after['consumed'],
                    'entries_count' => $account->entries_count + 1,
                ])->save();

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests raced on the same idempotency key before either could see the other's entry.
            $existing = QuotaLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();

            return $this->replay($existing, $pack, $type, $quantity);
        }
    }

    private function normalize(LedgerEntryType $type, string $quantity): string
    {
        if (! preg_match('/^-?\d{1,22}(\.\d{1,6})?$/', $quantity)) {
            throw QuotaException::invalidQuantity();
        }

        $value = YemQuantity::signed($quantity);
        $isZero = YemQuantity::isZero($value);
        $isNegative = YemQuantity::compare($value, '0') < 0;

        if ($isZero || ($isNegative && $type !== LedgerEntryType::Adjust)) {
            throw QuotaException::invalidQuantity();
        }

        return $value;
    }

    /**
     * @return array{available: string, reserved: string, consumed: string}
     */
    private function deltas(LedgerEntryType $type, string $quantity): array
    {
        $zero = YemQuantity::zero();
        $negative = YemQuantity::negate($quantity);

        return match ($type) {
            LedgerEntryType::Credit, LedgerEntryType::CashbackCredit => ['available' => $quantity, 'reserved' => $zero, 'consumed' => $zero],
            LedgerEntryType::Reserve => ['available' => $negative, 'reserved' => $quantity, 'consumed' => $zero],
            LedgerEntryType::Release => ['available' => $quantity, 'reserved' => $negative, 'consumed' => $zero],
            LedgerEntryType::Consume => ['available' => $zero, 'reserved' => $negative, 'consumed' => $quantity],
            LedgerEntryType::Expire => ['available' => $negative, 'reserved' => $zero, 'consumed' => $zero],
            LedgerEntryType::Adjust => ['available' => $quantity, 'reserved' => $zero, 'consumed' => $zero],
        };
    }

    private function assertReservable(UserPack $pack, QuotaAccount $account, string $quantity): void
    {
        $fresh = UserPack::query()->with('version')->findOrFail($pack->id);

        $termEnded = $fresh->expires_at !== null && $fresh->expires_at->isPast() && $fresh->version->end_of_term === EndOfTermPolicy::Expire;

        if ($fresh->status !== UserPackStatus::Active || $termEnded) {
            throw QuotaException::packNotUsable();
        }

        $min = $fresh->version->min_operation;
        $max = $fresh->version->max_operation;

        if (($min !== null && YemQuantity::compare($quantity, $min) < 0) || ($max !== null && YemQuantity::compare($quantity, $max) > 0)) {
            throw QuotaException::outsideOperationLimits($min, $max);
        }
    }

    /**
     * @param  array{available: string, reserved: string, consumed: string}  $after
     */
    private function assertNonNegative(LedgerEntryType $type, QuotaAccount $account, array $after, string $quantity): void
    {
        if (YemQuantity::compare($after['available'], '0') < 0) {
            throw QuotaException::insufficientAvailable($account->available, $quantity);
        }

        if (YemQuantity::compare($after['reserved'], '0') < 0) {
            throw QuotaException::insufficientReserved($account->reserved, $quantity);
        }
    }

    /**
     * A replay is only legitimate when it describes exactly the same movement on the same pack.
     */
    private function replay(QuotaLedgerEntry $existing, UserPack $pack, LedgerEntryType $type, string $quantity): QuotaLedgerEntry
    {
        $deltas = $this->deltas($type, $quantity);

        $sameOperation = $existing->user_pack_id === $pack->id
            && $existing->type === $type
            && YemQuantity::compare($existing->available_delta, $deltas['available']) === 0
            && YemQuantity::compare($existing->reserved_delta, $deltas['reserved']) === 0
            && YemQuantity::compare($existing->consumed_delta, $deltas['consumed']) === 0;

        return $sameOperation ? $existing : throw QuotaException::idempotencyConflict($existing->idempotency_key);
    }
}
