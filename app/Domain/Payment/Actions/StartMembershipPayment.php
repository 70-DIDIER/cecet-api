<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\MembershipFee;
use App\Domain\Membership\Models\Membership;
use App\Domain\Payment\CollectionRouter;
use App\Domain\Payment\Enums\PaymentPurpose;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Payment\PaymentCheckout;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Opens a provider checkout for the membership fee.
 *
 * The amount is always recomputed server-side from the versioned settings, fees included. An in-flight
 * checkout is reused instead of duplicated, and a changed amount replaces the unpaid intent.
 */
class StartMembershipPayment
{
    public function __construct(
        private readonly CollectionRouter $router,
        private readonly PaymentCheckout $checkout,
        private readonly MembershipFee $fees,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{attempt: PaymentAttempt, created: bool}
     */
    public function handle(User $member, ?string $method = null): array
    {
        if (! $member->isMember()) {
            throw PaymentException::notPayable();
        }

        if ($member->status === UserStatus::Active) {
            throw PaymentException::alreadyMember();
        }

        if ($member->status !== UserStatus::PendingPayment) {
            throw PaymentException::notPayable();
        }

        $channel = $this->router->route($member, $method);

        ['attempt' => $attempt, 'reused' => $reused] = DB::transaction(fn (): array => $this->prepare($member, $channel['provider'], $channel['method']));

        if ($reused || $channel['gateway'] === null) {
            return ['attempt' => $attempt, 'created' => ! $reused];
        }

        $attempt = $this->checkout->dispatch(
            $attempt,
            $channel['gateway'],
            $member,
            PaymentPurpose::Membership,
            "Adhésion CECT {$member->member_number} (frais de transaction inclus)",
        );

        return ['attempt' => $attempt, 'created' => true];
    }

    /**
     * @return array{attempt: PaymentAttempt, reused: bool}
     */
    private function prepare(User $member, string $providerName, string $method): array
    {
        User::query()->lockForUpdate()->findOrFail($member->id);

        $fee = $this->fees->for($member);

        $membership = Membership::query()->firstOrCreate(
            ['user_id' => $member->id],
            [
                'status' => MembershipStatus::PendingPayment,
                'fee_amount' => $fee['amount'],
                'fee_currency' => MembershipFee::CURRENCY,
                'fee_setting_key' => $fee['setting_key'],
            ],
        );

        if ($membership->status === MembershipStatus::Active) {
            throw PaymentException::alreadyMember();
        }

        return $this->checkout->prepare(
            $member,
            $membership,
            PaymentPurpose::Membership,
            $fee['amount'],
            $providerName,
            ['fee_setting_key' => $fee['setting_key']],
            function (PaymentIntent $intent, ?PaymentIntent $replaced) use ($membership, $member, $fee): void {
                if ($replaced !== null) {
                    $this->auditLogger->record(
                        action: 'memberships.fee_changed',
                        subject: $membership,
                        before: ['fee_amount' => $membership->fee_amount, 'total_amount' => $replaced->total_amount, 'payment_intent_id' => $replaced->id],
                        after: ['fee_amount' => $fee['amount'], 'total_amount' => $intent->total_amount],
                        reason: 'Le montant à payer (frais d\'adhésion ou frais de transaction) a changé avant tout paiement.',
                        actor: $member,
                    );
                }

                $membership->forceFill(['fee_amount' => $fee['amount'], 'fee_setting_key' => $fee['setting_key']])->save();
            },
            $method,
        );
    }
}
