<?php

namespace App\Domain\Merchant;

use App\Domain\Merchant\Enums\MerchantLedgerEntryType;
use App\Domain\Merchant\Exceptions\MerchantException;
use App\Domain\Merchant\Models\MerchantAccount;
use App\Domain\Merchant\Models\MerchantLedgerEntry;
use App\Domain\Quota\Exceptions\QuotaException;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use App\Support\YemQuantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a merchant's balance, with the same guarantees as the quota ledger: one transaction locking the
 * account row, exact decimals, an immutable entry, a projection that can never go negative, and an idempotency key
 * per movement.
 */
class MerchantLedger
{
    /**
     * A collection confirmed: eligible += quantity.
     */
    public function credit(UserPack $pack, string $quantity, string $idempotencyKey, ?Model $source = null, ?string $reason = null): MerchantLedgerEntry
    {
        return $this->apply($pack, MerchantLedgerEntryType::Credit, $quantity, $idempotencyKey, $source, $reason);
    }

    /**
     * Sets eligible balance aside for a redemption: eligible -= quantity, reserved += quantity.
     */
    public function reserve(UserPack $pack, string $quantity, string $idempotencyKey, ?Model $source = null): MerchantLedgerEntry
    {
        return $this->apply($pack, MerchantLedgerEntryType::Reserve, $quantity, $idempotencyKey, $source);
    }

    /**
     * Gives a reservation back: reserved -= quantity, eligible += quantity.
     */
    public function release(UserPack $pack, string $quantity, string $idempotencyKey, ?Model $source = null, ?string $reason = null): MerchantLedgerEntry
    {
        return $this->apply($pack, MerchantLedgerEntryType::Release, $quantity, $idempotencyKey, $source, $reason);
    }

    /**
     * A redemption confirmed: reserved -= quantity, redeemed += quantity.
     */
    public function consume(UserPack $pack, string $quantity, string $idempotencyKey, ?Model $source = null): MerchantLedgerEntry
    {
        return $this->apply($pack, MerchantLedgerEntryType::Consume, $quantity, $idempotencyKey, $source);
    }

    /**
     * Audited manual correction of the eligible balance, positive or negative, never below zero.
     */
    public function adjust(UserPack $pack, string $signedQuantity, string $idempotencyKey, User $actor, string $reason): MerchantLedgerEntry
    {
        return $this->apply($pack, MerchantLedgerEntryType::Adjust, $signedQuantity, $idempotencyKey, reason: $reason, actor: $actor);
    }

    private function apply(
        UserPack $pack,
        MerchantLedgerEntryType $type,
        string $quantity,
        string $idempotencyKey,
        ?Model $source = null,
        ?string $reason = null,
        ?User $actor = null,
    ): MerchantLedgerEntry {
        $quantity = $type === MerchantLedgerEntryType::Adjust ? YemQuantity::signed($quantity) : YemQuantity::of($quantity);

        if (YemQuantity::isZero($quantity) || ($type !== MerchantLedgerEntryType::Adjust && YemQuantity::compare($quantity, '0') < 0)) {
            throw QuotaException::invalidQuantity();
        }

        try {
            return DB::transaction(function () use ($pack, $type, $quantity, $idempotencyKey, $source, $reason, $actor): MerchantLedgerEntry {
                MerchantAccount::query()->insertOrIgnore(['user_pack_id' => $pack->id, 'created_at' => now(), 'updated_at' => now()]);
                $account = MerchantAccount::query()->lockForUpdate()->findOrFail($pack->id);

                $existing = MerchantLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $this->replay($existing, $pack, $type);
                }

                $negative = YemQuantity::negate($quantity);
                $zero = YemQuantity::zero();
                [$eligible, $reserved, $redeemed] = match ($type) {
                    MerchantLedgerEntryType::Credit, MerchantLedgerEntryType::Adjust => [$quantity, $zero, $zero],
                    MerchantLedgerEntryType::Reserve => [$negative, $quantity, $zero],
                    MerchantLedgerEntryType::Release => [$quantity, $negative, $zero],
                    MerchantLedgerEntryType::Consume => [$zero, $negative, $quantity],
                };

                $after = [
                    'eligible' => YemQuantity::add($account->eligible, $eligible),
                    'reserved' => YemQuantity::add($account->reserved, $reserved),
                    'redeemed' => YemQuantity::add($account->redeemed, $redeemed),
                ];

                if (YemQuantity::compare($after['eligible'], '0') < 0 || YemQuantity::compare($after['reserved'], '0') < 0) {
                    throw MerchantException::insufficientBalance($account->eligible, $account->reserved);
                }

                $entry = MerchantLedgerEntry::query()->create([
                    'user_pack_id' => $pack->id,
                    'type' => $type,
                    'eligible_delta' => $eligible,
                    'reserved_delta' => $reserved,
                    'redeemed_delta' => $redeemed,
                    'eligible_after' => $after['eligible'],
                    'reserved_after' => $after['reserved'],
                    'redeemed_after' => $after['redeemed'],
                    'idempotency_key' => $idempotencyKey,
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source !== null ? (string) $source->getKey() : null,
                    'reason' => $reason,
                    'actor_id' => $actor?->id,
                    'correlation_id' => Context::get('correlation_id'),
                    'created_at' => now(),
                ]);

                $account->forceFill([...$after, 'entries_count' => $account->entries_count + 1])->save();

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->replay(MerchantLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->firstOrFail(), $pack, $type);
        }
    }

    private function replay(MerchantLedgerEntry $existing, UserPack $pack, MerchantLedgerEntryType $type): MerchantLedgerEntry
    {
        if ($existing->user_pack_id !== $pack->id || $existing->type !== $type) {
            throw QuotaException::idempotencyConflict($existing->idempotency_key);
        }

        return $existing;
    }
}
