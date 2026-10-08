<?php

namespace App\Domain\Merchant\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exchange\Enums\ExchangeKind;
use App\Domain\Exchange\Enums\ExchangeStatus;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Exchange\ExchangeReservations;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Payout\Exceptions\PayoutException;
use App\Domain\Payout\Models\PayoutDestination;
use App\Domain\Quota\Exceptions\QuotaException;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use App\Support\YemQuantity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A merchant asks the CECT to redeem YEM collected from members (cahier v4.0 §6.3).
 *
 * The request reserves the quantity on the merchant's balance of confirmed collections and, when configured, on
 * the periodic quota of its Pack Marchand. The merchant then transfers the YEM to the CECT Pernum and declares it;
 * the rest of the journey is the one of any exchange: Validator confirmation, counter-value, payout.
 */
class MerchantRedemptionService
{
    private const DEFAULT_RATE_KEY = 'rates.purchase_xof_per_yem';

    public function __construct(
        private readonly MerchantQrService $merchants,
        private readonly ExchangeReservations $reservations,
        private readonly SettingsService $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function create(User $merchant, UserPack $pack, string $quantity): ExchangeRequest
    {
        $this->merchants->assertUsableMerchantPack($merchant, $pack);

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

        $sourcePernum = $this->merchants->merchantPernum($merchant);
        $destination = PayoutDestination::query()->where('user_id', $merchant->id)->first() ?? throw PayoutException::destinationRequired();
        $rateKey = (string) ($pack->version->rules['rate_setting_key'] ?? self::DEFAULT_RATE_KEY);
        $onPackQuota = $this->settings->boolean('merchant_redemption.limit_by_pack_quota');

        return DB::transaction(function () use ($merchant, $pack, $quantity, $cectPernum, $sourcePernum, $destination, $rateKey, $onPackQuota): ExchangeRequest {
            $exchange = ExchangeRequest::query()->create([
                'reference' => 'RD-'.str_pad((string) DB::scalar("select nextval('exchange_reference_seq')"), 7, '0', STR_PAD_LEFT),
                'kind' => ExchangeKind::MerchantRedemption,
                'user_id' => $merchant->id,
                'user_pack_id' => $pack->id,
                'status' => ExchangeStatus::AwaitingTransfer,
                'quantity_requested' => $quantity,
                'rate_setting_key' => $rateKey,
                'rate_xof_per_yem' => $this->settings->integer($rateKey),
                'source_pernum' => $sourcePernum,
                'cect_pernum' => trim($cectPernum),
                'payout_destination' => $destination->snapshot(),
                'expires_at' => now()->addMinutes($this->settings->integer('exchange.reservation_ttl_minutes')),
            ]);

            $exchange->setRelation('userPack', $pack);
            $this->reservations->reserve($exchange, $onPackQuota);

            $this->auditLogger->record('merchant_redemptions.created', $exchange, after: [
                'reference' => $exchange->reference,
                'quantity_requested' => $quantity,
                'rate_xof_per_yem' => $exchange->rate_xof_per_yem,
                'limited_by_pack_quota' => $onPackQuota,
            ], actor: $merchant);

            return $exchange;
        });
    }
}
