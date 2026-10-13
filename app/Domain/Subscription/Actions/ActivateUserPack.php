<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Notification\Notifier;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Quota\Models\QuotaAccount;
use App\Domain\Subscription\CashbackCalculator;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Domain\Subscription\Models\PackPeriod;
use App\Domain\Subscription\Models\UserPack;
use App\Domain\Subscription\PeriodRoller;
use App\Support\YemQuantity;

/**
 * Activates a purchased pack once its payment is settled: generates the periods, opens the quota account
 * and credits the first allowance. Must run inside the settling transaction.
 *
 * The pack keeps the exact version it was bought with: later catalog changes never touch it.
 */
class ActivateUserPack
{
    public function __construct(
        private readonly PeriodRoller $roller,
        private readonly CashbackCalculator $cashback,
        private readonly AuditLogger $auditLogger,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Number of periods to wait before the first allowance is credited. A Vendeur pack only lets its holder sell after
     * the first month from the subscription, unless the version says otherwise ("first_credit_delay_periods").
     */
    private function firstCreditDelay(UserPack $pack): int
    {
        $configured = $pack->version->rules['first_credit_delay_periods'] ?? null;

        if (is_int($configured) && $configured >= 0) {
            return $configured;
        }

        return $pack->family === PackFamily::Vendeur ? 1 : 0;
    }

    public function handle(UserPack $pack, ?PaymentIntent $intent = null): UserPack
    {
        $pack = UserPack::query()->with(['version', 'plan'])->lockForUpdate()->findOrFail($pack->id);

        if ($pack->status !== UserPackStatus::PendingPayment) {
            return $pack;
        }

        $version = $pack->version;
        $now = now()->toImmutable();
        $expiresAt = null;

        QuotaAccount::query()->create([
            'user_pack_id' => $pack->id,
            'available' => YemQuantity::zero(),
            'reserved' => YemQuantity::zero(),
            'consumed' => YemQuantity::zero(),
        ]);

        if ($version->period_unit !== null && $version->period_every !== null && $version->period_cycles !== null && $version->quota_per_period !== null) {
            $delay = $this->firstCreditDelay($pack);
            $cashbackShares = $this->cashback->spread($pack->yem_cashback, $version->period_cycles);

            for ($number = 1; $number <= $version->period_cycles; $number++) {
                $cashbackShare = $cashbackShares[$number - 1];

                $period = PackPeriod::query()->create([
                    'user_pack_id' => $pack->id,
                    'number' => $number,
                    'starts_at' => $version->period_unit->addTo($now, ($number - 1 + $delay) * $version->period_every),
                    'ends_at' => $version->period_unit->addTo($now, ($number + $delay) * $version->period_every),
                    'quota' => YemQuantity::add($version->quota_per_period, $cashbackShare),
                    'cashback_quota' => $cashbackShare,
                ]);

                $expiresAt = $period->ends_at;
            }
        }

        $pack->forceFill([
            'status' => UserPackStatus::Active,
            'paid_at' => $intent !== null ? $now : null,
            'activated_at' => $now,
            'expires_at' => $expiresAt,
        ])->save();

        $this->roller->creditDuePeriods($pack, $now);

        $this->auditLogger->record(
            action: 'packs.activated',
            subject: $pack,
            before: ['status' => UserPackStatus::PendingPayment->value],
            after: ['status' => UserPackStatus::Active->value, 'expires_at' => $expiresAt?->toIso8601ZuluString()],
            metadata: array_filter([
                'plan_code' => $pack->plan->code,
                'version' => $version->version,
                'price_xof' => $pack->price_xof,
                'transaction_fee' => $intent?->fee_amount,
                'total_paid' => $intent?->total_amount,
                'payment_intent_id' => $intent?->id,
            ], fn (mixed $value): bool => $value !== null),
            actor: $pack->user,
        );

        $this->notifier->notify($pack->user, 'pack.activated', ['pack' => $pack->plan->code]);

        return $pack->refresh();
    }
}
