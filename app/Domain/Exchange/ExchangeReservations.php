<?php

namespace App\Domain\Exchange;

use App\Domain\Exchange\Enums\ExchangeKind;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Merchant\MerchantLedger;
use App\Domain\Quota\Models\QuotaLedgerEntry;
use App\Domain\Quota\QuotaLedger;

/**
 * The ledger movements behind an exchange request, whatever its kind.
 *
 * - Vendeur sale: the quantity is reserved on the pack's quota.
 * - Merchant redemption: on the merchant's balance of collections (merchant ledger) and, when the pack's periodic
 *   quota caps redemptions, on that quota as well.
 *
 * Must run inside the caller's transaction. Every movement carries an idempotency key derived from the request.
 */
class ExchangeReservations
{
    public function __construct(
        private readonly QuotaLedger $quotaLedger,
        private readonly MerchantLedger $merchantLedger,
    ) {}

    public function reserve(ExchangeRequest $exchange, bool $onPackQuota = true): void
    {
        if ($exchange->kind === ExchangeKind::MerchantRedemption) {
            $this->merchantLedger->reserve($exchange->userPack, $exchange->quantity_requested, "exchange:{$exchange->id}:merchant_reserve", $exchange);
        }

        if ($onPackQuota) {
            $this->quotaLedger->reserve($exchange->userPack, $exchange->quantity_requested, "exchange:{$exchange->id}:reserve", $exchange);
        }
    }

    public function consume(ExchangeRequest $exchange, string $quantity): void
    {
        if ($exchange->kind === ExchangeKind::MerchantRedemption) {
            $this->merchantLedger->consume($exchange->userPack, $quantity, "exchange:{$exchange->id}:merchant_consume", $exchange);
        }

        if ($this->reservedOnPackQuota($exchange)) {
            $this->quotaLedger->consume($exchange->userPack, $quantity, "exchange:{$exchange->id}:consume", $exchange);
        }
    }

    public function release(ExchangeRequest $exchange, string $quantity, string $reason): void
    {
        if ($exchange->kind === ExchangeKind::MerchantRedemption) {
            $this->merchantLedger->release($exchange->userPack, $quantity, "exchange:{$exchange->id}:merchant_release", $exchange, $reason);
        }

        if ($this->reservedOnPackQuota($exchange)) {
            $this->quotaLedger->release($exchange->userPack, $quantity, "exchange:{$exchange->id}:release", $exchange, $reason);
        }
    }

    private function reservedOnPackQuota(ExchangeRequest $exchange): bool
    {
        return QuotaLedgerEntry::query()->where('idempotency_key', "exchange:{$exchange->id}:reserve")->exists();
    }
}
