<?php

namespace App\Domain\Exchange\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Exchange\Enums\ExchangeKind;
use App\Domain\Exchange\Enums\ExchangeStatus;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Exchange\ExchangeReservations;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Identity\Enums\PernumStatus;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Payout\Exceptions\PayoutException;
use App\Domain\Payout\Models\PayoutDestination;
use App\Domain\Quota\Exceptions\QuotaException;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Exceptions\SubscriptionException;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use App\Support\YemQuantity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The member's side of selling YEM to the CECT (Pack Vendeur).
 *
 * Creating a request reserves the quantity atomically on the pack's quota and snapshots the rate, the Pernums and
 * the payout destination. Declaring the transfer changes nothing to the quota and makes nothing payable: only the
 * Validator's confirmation does.
 */
class SellerExchangeService
{
    private const DEFAULT_RATE_KEY = 'rates.purchase_xof_per_yem';

    public function __construct(
        private readonly ExchangeReservations $reservations,
        private readonly SettingsService $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function create(User $member, UserPack $pack, string $quantity): ExchangeRequest
    {
        if (! $member->isMember() || $member->status !== UserStatus::Active) {
            throw SubscriptionException::memberNotActive();
        }

        if ($pack->user_id !== $member->id || $pack->family !== PackFamily::Vendeur) {
            throw ExchangeException::packNotEligible();
        }

        try {
            $quantity = YemQuantity::of($quantity);
        } catch (InvalidArgumentException) {
            throw QuotaException::invalidQuantity();
        }

        if (! YemQuantity::isPositive($quantity)) {
            throw QuotaException::invalidQuantity();
        }

        $cectPernum = $this->settings->string('yem.cect_pernum');

        if ($cectPernum === null || trim($cectPernum) === '') {
            throw ExchangeException::cectPernumNotConfigured();
        }

        $sourcePernum = $member->pernumIdentities()
            ->whereIn('status', [PernumStatus::Declared, PernumStatus::Verified])
            ->latest()
            ->value('pernum') ?? throw ExchangeException::pernumRequired();

        $destination = PayoutDestination::query()->where('user_id', $member->id)->first() ?? throw PayoutException::destinationRequired();

        $rateKey = (string) ($pack->version->rules['rate_setting_key'] ?? self::DEFAULT_RATE_KEY);

        return DB::transaction(function () use ($member, $pack, $quantity, $cectPernum, $sourcePernum, $destination, $rateKey): ExchangeRequest {
            $exchange = ExchangeRequest::query()->create([
                'reference' => 'EX-'.str_pad((string) DB::scalar("select nextval('exchange_reference_seq')"), 7, '0', STR_PAD_LEFT),
                'kind' => ExchangeKind::Seller,
                'user_id' => $member->id,
                'user_pack_id' => $pack->id,
                'status' => ExchangeStatus::AwaitingTransfer,
                'quantity_requested' => $quantity,
                'rate_setting_key' => $rateKey,
                'rate_xof_per_yem' => $this->settings->integer($rateKey),
                'source_pernum' => (string) $sourcePernum,
                'cect_pernum' => trim($cectPernum),
                'payout_destination' => $destination->snapshot(),
                'expires_at' => now()->addMinutes($this->settings->integer('exchange.reservation_ttl_minutes')),
            ]);

            $exchange->setRelation('userPack', $pack);
            $this->reservations->reserve($exchange);

            $this->auditLogger->record('exchanges.created', $exchange, after: [
                'reference' => $exchange->reference,
                'quantity_requested' => $quantity,
                'rate_xof_per_yem' => $exchange->rate_xof_per_yem,
                'rate_setting_key' => $rateKey,
            ], actor: $member);

            return $exchange;
        });
    }

    /**
     * The member says the YEM were sent. Informational only: no quota movement, nothing becomes payable.
     */
    public function declareSent(User $member, ExchangeRequest $exchange, ?string $transferReference): ExchangeRequest
    {
        return DB::transaction(function () use ($member, $exchange, $transferReference): ExchangeRequest {
            $exchange = ExchangeRequest::query()->lockForUpdate()->findOrFail($exchange->id);

            if ($exchange->status === ExchangeStatus::DeclaredSent) {
                return $exchange;
            }

            if ($exchange->status !== ExchangeStatus::AwaitingTransfer) {
                throw ExchangeException::notOpen($exchange->status->value);
            }

            $exchange->forceFill([
                'status' => ExchangeStatus::DeclaredSent,
                'declared_at' => now(),
                'member_transfer_reference' => $transferReference,
            ])->save();

            $this->auditLogger->record('exchanges.declared_sent', $exchange, before: ['status' => ExchangeStatus::AwaitingTransfer->value], after: ['status' => ExchangeStatus::DeclaredSent->value, 'member_transfer_reference' => $transferReference], actor: $member);

            return $exchange;
        });
    }

    public function cancel(User $member, ExchangeRequest $exchange): ExchangeRequest
    {
        return $this->close($exchange, ExchangeStatus::Canceled, 'exchanges.canceled', 'Annulée par le membre avant le transfert', $member);
    }

    /**
     * Requests still awaiting a transfer after their reservation delay give their quota back.
     */
    public function expireStale(): int
    {
        $expired = 0;

        ExchangeRequest::query()
            ->where('status', ExchangeStatus::AwaitingTransfer)
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($exchanges) use (&$expired): void {
                foreach ($exchanges as $exchange) {
                    $this->close($exchange, ExchangeStatus::Expired, 'exchanges.expired', 'Délai de réservation dépassé sans transfert');
                    $expired++;
                }
            });

        return $expired;
    }

    private function close(ExchangeRequest $exchange, ExchangeStatus $target, string $action, string $reason, ?User $actor = null): ExchangeRequest
    {
        return DB::transaction(function () use ($exchange, $target, $action, $reason, $actor): ExchangeRequest {
            $exchange = ExchangeRequest::query()->with('userPack')->lockForUpdate()->findOrFail($exchange->id);

            if ($exchange->status !== ExchangeStatus::AwaitingTransfer) {
                throw ExchangeException::notCancelable($exchange->status->value);
            }

            $this->reservations->release($exchange, $exchange->quantity_requested, $reason);

            $exchange->forceFill(['status' => $target, 'closed_at' => now()])->save();
            $this->auditLogger->record($action, $exchange, before: ['status' => ExchangeStatus::AwaitingTransfer->value], after: ['status' => $target->value], reason: $reason, actor: $actor);

            return $exchange;
        });
    }
}
