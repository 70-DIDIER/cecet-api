<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Payment\CollectionRouter;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentIntentStatus;
use App\Domain\Payment\Enums\PaymentPurpose;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\PaymentCheckout;
use App\Domain\Quota\Exceptions\QuotaException;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\CashbackCalculator;
use App\Domain\Subscription\Enums\PackTopupStatus;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Domain\Subscription\Exceptions\SubscriptionException;
use App\Domain\Subscription\Models\PackTopup;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use App\Support\YemQuantity;
use Illuminate\Support\Facades\DB;

/**
 * A member tops up their Personnel pack ("rechargement Perso"): they choose the amount, at least the minimum of the
 * pack's version, and receive the YEM it buys once the payment is confirmed or validated.
 *
 * The amount paid is never reduced: 5 000 F at 650 F buy 7.692307 YEM, and the cashback rule (Superadmin setting) adds
 * its bonus in YEM on top. The rate and the cashback calculation are snapshotted on the top-up. One top-up at a time is awaiting payment per
 * pack: asking again with the same amount resumes it, a different amount replaces it if nothing was paid or declared.
 */
class RequestPackTopup
{
    private const DEFAULT_RATE_KEY = 'rates.sale_xof_per_yem';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly CashbackCalculator $cashback,
        private readonly CollectionRouter $router,
        private readonly PaymentCheckout $checkout,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{topup: PackTopup, attempt: PaymentAttempt, created: bool}
     */
    public function handle(User $member, UserPack $pack, int $amount, ?string $method = null): array
    {
        if (! $member->isMember() || $member->status !== UserStatus::Active) {
            throw SubscriptionException::memberNotActive();
        }

        if (! $member->hasVerifiedIdentity()) {
            throw SubscriptionException::identityNotVerified();
        }

        $channel = $this->router->route($member, $method);

        $result = DB::transaction(fn (): array => $this->prepare($member, $pack, $amount, $channel['provider'], $channel['method']));

        if ($result['reused'] || $channel['gateway'] === null) {
            return ['topup' => $result['topup'], 'attempt' => $result['attempt'], 'created' => ! $result['reused']];
        }

        $attempt = $this->checkout->dispatch(
            $result['attempt'],
            $channel['gateway'],
            $member,
            PaymentPurpose::PackTopup,
            'Rechargement Perso (frais de transaction inclus)',
            ['pack_topup_id' => $result['topup']->id],
        );

        return ['topup' => $result['topup'], 'attempt' => $attempt, 'created' => true];
    }

    /**
     * @return array{topup: PackTopup, attempt: PaymentAttempt, reused: bool}
     */
    private function prepare(User $member, UserPack $pack, int $amount, string $providerName, string $method): array
    {
        $pack = UserPack::query()->with('version')->lockForUpdate()->findOrFail($pack->id);

        if ($pack->family !== PackFamily::Personnel || $pack->status !== UserPackStatus::Active) {
            throw SubscriptionException::topupNotAvailable();
        }

        $rules = $pack->version->rules;
        $minimum = is_int($rules['min_topup_xof'] ?? null) ? $rules['min_topup_xof'] : 1;

        if ($amount < $minimum) {
            throw SubscriptionException::topupBelowMinimum($minimum);
        }

        $topup = PackTopup::query()
            ->where('user_pack_id', $pack->id)
            ->where('status', PackTopupStatus::PendingPayment)
            ->first();

        if ($topup !== null && $topup->amount_xof !== $amount) {
            $this->abandon($topup);
            $topup = null;
        }

        if ($topup === null) {
            $rateKey = is_string($rules['rate_setting_key'] ?? null) ? $rules['rate_setting_key'] : self::DEFAULT_RATE_KEY;
            $rate = $this->settings->integer($rateKey);
            $quote = $this->cashback->quote(
                $pack->family,
                bcdiv((string) $amount, (string) $rate, YemQuantity::SCALE),
                is_array($rules['cashback'] ?? null) ? $rules['cashback'] : null,
                $pack->version->published_at?->toIso8601String(),
            );

            if (! YemQuantity::isPositive($quote->yemBase)) {
                throw QuotaException::invalidQuantity();
            }

            $topup = PackTopup::query()->create([
                'user_id' => $member->id,
                'user_pack_id' => $pack->id,
                'status' => PackTopupStatus::PendingPayment,
                'amount_xof' => $amount,
                'cashback_bps' => $quote->rateBps,
                'yem_base' => $quote->yemBase,
                'yem_cashback' => $quote->yemCashback,
                'cashback_rounding' => $quote->rounding,
                'cashback_rule_effective_from' => $quote->ruleEffectiveFrom,
                'calculated_at' => $quote->calculatedAt,
                'rate_setting_key' => $rateKey,
                'rate_xof_per_yem' => $rate,
                'quantity' => $quote->yemTotal,
            ]);

            $this->auditLogger->record('packs.topup_requested', $topup, after: [
                'amount_xof' => $amount,
                'cashback_bps' => $quote->rateBps,
                'rate_xof_per_yem' => $rate,
                'yem_base' => $quote->yemBase,
                'yem_cashback' => $quote->yemCashback,
                'quantity' => $topup->quantity,
            ], actor: $member);
        }

        $prepared = $this->checkout->prepare(
            $member,
            $topup,
            PaymentPurpose::PackTopup,
            $amount,
            $providerName,
            ['user_pack_id' => $pack->id],
            null,
            $method,
        );

        return ['topup' => $topup, 'attempt' => $prepared['attempt'], 'reused' => $prepared['reused']];
    }

    /**
     * Replaces an unpaid top-up. Refused while a payment may still land on it: a checkout open at the provider,
     * or a manual payment the member already declared.
     */
    private function abandon(PackTopup $topup): void
    {
        $this->checkout->expireStale($topup);

        $attempts = PaymentAttempt::query()
            ->whereIn('payment_intent_id', $topup->paymentIntents()->select('id'))
            ->whereIn('status', [PaymentAttemptStatus::Created, PaymentAttemptStatus::Pending])
            ->get();

        foreach ($attempts as $attempt) {
            if ($attempt->provider !== CollectionRouter::MANUAL_PROVIDER || $attempt->declared_at !== null) {
                throw SubscriptionException::topupInProgress();
            }
        }

        foreach ($attempts as $attempt) {
            $attempt->forceFill(['status' => PaymentAttemptStatus::Canceled, 'settled_at' => now()])->save();
        }

        $topup->paymentIntents()
            ->where('status', PaymentIntentStatus::Open)
            ->update(['status' => PaymentIntentStatus::Canceled, 'canceled_at' => now()]);

        $topup->forceFill(['status' => PackTopupStatus::Canceled, 'canceled_at' => now()])->save();
    }
}
