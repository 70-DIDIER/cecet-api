<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Catalog\Enums\PackFamily;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Catalog\Models\PackVersion;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Payment\CollectionRouter;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentPurpose;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\PaymentCheckout;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\CashbackCalculator;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Domain\Subscription\Exceptions\SubscriptionException;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use App\Support\YemQuantity;
use Illuminate\Support\Facades\DB;

/**
 * A member subscribes to a published pack.
 *
 * The server alone decides eligibility, the structured-pack limit (configurable, Personnel excluded by default)
 * and the price, which is snapshotted from the current published version. The pack only becomes active when the
 * payment, transaction fees included, is confirmed by the provider. A free pack activates immediately.
 */
class SubscribeToPack
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly CashbackCalculator $cashback,
        private readonly CollectionRouter $router,
        private readonly PaymentCheckout $checkout,
        private readonly ActivateUserPack $activate,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{user_pack: UserPack, attempt: PaymentAttempt|null, created: bool}
     */
    public function handle(User $member, PackPlan $plan, ?string $method = null): array
    {
        if (! $member->isMember() || $member->status !== UserStatus::Active) {
            throw SubscriptionException::memberNotActive();
        }

        $channel = $this->router->route($member, $method);

        $result = DB::transaction(fn (): array => $this->prepare($member, $plan, $channel['provider'], $channel['method']));

        if ($result['attempt'] === null || $result['reused'] || $channel['gateway'] === null) {
            return ['user_pack' => $result['user_pack'], 'attempt' => $result['attempt'], 'created' => $result['attempt'] === null || ! $result['reused']];
        }

        $attempt = $this->checkout->dispatch(
            $result['attempt'],
            $channel['gateway'],
            $member,
            PaymentPurpose::PackSubscription,
            "Pack {$result['user_pack']->plan->code} (frais de transaction inclus)",
            ['user_pack_id' => $result['user_pack']->id],
        );

        return ['user_pack' => $result['user_pack'], 'attempt' => $attempt, 'created' => true];
    }

    /**
     * @return array{user_pack: UserPack, attempt: PaymentAttempt|null, reused: bool}
     */
    private function prepare(User $member, PackPlan $plan, string $providerName, string $method): array
    {
        User::query()->lockForUpdate()->findOrFail($member->id);

        $plan = PackPlan::query()->with('currentVersion')->findOrFail($plan->id);
        $version = $plan->currentVersion;

        if (! $plan->status->isSubscribable() || $version === null) {
            throw SubscriptionException::planNotAvailable();
        }

        // Enforce the PF/PM family boundary even for already-published legacy versions
        // that were configured before this decision. Eligibility metadata alone is insufficient.
        $allowed = $plan->family->defaultEligiblePersonTypes();
        if (! in_array($member->person_type?->value, $allowed, true)
            || ! in_array($member->person_type?->value, $version->eligible_person_types, true)) {
            throw SubscriptionException::notEligible($allowed);
        }

        $pack = UserPack::query()
            ->where('user_id', $member->id)
            ->where('pack_plan_id', $plan->id)
            ->where('status', UserPackStatus::PendingPayment)
            ->first();

        if ($pack === null) {
            $this->assertWithinStructuredLimit($member, $plan);

            $pack = UserPack::query()->create([
                'user_id' => $member->id,
                'pack_plan_id' => $plan->id,
                'pack_version_id' => $version->id,
                'family' => $plan->family,
                'status' => UserPackStatus::PendingPayment,
                'price_xof' => $version->price_xof,
            ]);

            $this->freezeCashback($pack, $version);

            $this->auditLogger->record('packs.subscribed', $pack, after: [
                'plan_code' => $plan->code,
                'version' => $version->version,
                'price_xof' => $version->price_xof,
                'cashback_bps' => $pack->cashback_bps,
                'yem_base' => $pack->yem_base,
                'yem_cashback' => $pack->yem_cashback,
            ], actor: $member);
        } elseif ($pack->pack_version_id !== $version->id && ! $this->hasInFlightAttempt($pack)) {
            // Still unpaid and nothing awaiting payment: the member buys what is currently published.
            $pack->forceFill(['pack_version_id' => $version->id, 'price_xof' => $version->price_xof])->save();
            $this->freezeCashback($pack, $version);
        }

        $pack->setRelation('plan', $plan);

        if ($pack->price_xof === 0) {
            return ['user_pack' => $this->activate->handle($pack), 'attempt' => null, 'reused' => false];
        }

        $prepared = $this->checkout->prepare(
            $member,
            $pack,
            PaymentPurpose::PackSubscription,
            $pack->price_xof,
            $providerName,
            ['plan_code' => $plan->code, 'pack_version_id' => $pack->pack_version_id],
            null,
            $method,
        );

        return ['user_pack' => $pack, 'attempt' => $prepared['attempt'], 'reused' => $prepared['reused']];
    }

    /**
     * The YEM the pack grants over its whole term and the cashback offered on top are frozen with the purchase:
     * later changes of the rule never recalculate it. The price paid is untouched.
     */
    private function freezeCashback(UserPack $pack, PackVersion $version): void
    {
        if ($version->quota_per_period === null || $version->period_cycles === null) {
            return;
        }

        $quote = $this->cashback->quote(
            $pack->family,
            YemQuantity::times($version->quota_per_period, $version->period_cycles),
            is_array($version->rules['cashback'] ?? null) ? $version->rules['cashback'] : null,
            $version->published_at?->toIso8601String(),
        );

        $pack->forceFill([
            'cashback_bps' => $quote->rateBps,
            'yem_base' => $quote->yemBase,
            'yem_cashback' => $quote->yemCashback,
            'cashback_rounding' => $quote->rounding,
            'cashback_rule_effective_from' => $quote->ruleEffectiveFrom,
            'calculated_at' => $quote->calculatedAt,
        ])->save();
    }

    /**
     * A natural person may hold at most two active/pending SELLER pack
     * subscriptions, including two subscriptions of the same formula.
     * Consumer packs do not consume these seller slots; Personnel remains
     * a balance top-up product. User lock in prepare() serializes purchases.
     */
    private function assertWithinStructuredLimit(User $member, PackPlan $plan): void
    {
        if ($plan->family !== PackFamily::Vendeur) {
            return;
        }

        $max = $this->settings->integer('packs.max_structured_active');
        $held = UserPack::query()
            ->where('user_id', $member->id)
            ->where('family', PackFamily::Vendeur->value)
            ->whereIn('status', [UserPackStatus::PendingPayment, UserPackStatus::Active])
            ->count();

        if ($held >= $max) {
            throw SubscriptionException::limitReached($max);
        }
    }

    private function hasInFlightAttempt(UserPack $pack): bool
    {
        return PaymentAttempt::query()
            ->whereIn('payment_intent_id', $pack->paymentIntents()->select('id'))
            ->whereIn('status', [PaymentAttemptStatus::Created, PaymentAttemptStatus::Pending])
            ->exists();
    }
}
