<?php

namespace App\Domain\Membership\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Kyc\KycEligibility;
use App\Domain\Membership\Enums\ActivationMode;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Models\Membership;
use App\Domain\Notification\Notifier;
use App\Domain\Payment\Models\PaymentIntent;
use App\Models\User;

/**
 * Activates the member account once the membership is settled. Must run inside the settling transaction.
 *
 * Only an account waiting for payment becomes ACTIVE: a payment received while the account is suspended
 * or closed is recorded but never reactivates it.
 */
class ActivateMembership
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly SecurityEventLogger $securityLogger,
        private readonly Notifier $notifier,
    ) {}

    public function handle(Membership $membership, ActivationMode $mode, ?PaymentIntent $intent = null): void
    {
        $user = User::query()->lockForUpdate()->findOrFail($membership->user_id);

        if ($membership->status === MembershipStatus::Active) {
            return;
        }

        if ($mode === ActivationMode::Payment) {
            $membership->paid_at = now();
        }

        if ($user->status !== UserStatus::PendingPayment || ! (KycEligibility::approved($user)
            || ($mode === ActivationMode::SimulatedPayment && KycEligibility::stagingMembershipDemo($user)))) {
            $membership->save();

            $this->auditLogger->record(
                action: 'memberships.paid_without_activation',
                subject: $membership,
                after: ['account_status' => $user->status->value, 'payment_intent_id' => $intent?->id],
                metadata: ['amount' => $intent?->amount],
            );
            $this->securityLogger->record('membership.payment_on_inactive_account', SecuritySeverity::Warning, $user->id, metadata: [
                'account_status' => $user->status->value,
                'payment_intent_id' => $intent?->id,
            ]);

            return;
        }

        $user->forceFill(['status' => UserStatus::Active])->save();
        $membership->forceFill([
            'status' => MembershipStatus::Active,
            'activated_at' => now(),
            'activation_mode' => $mode,
        ])->save();

        $this->auditLogger->record(
            action: 'memberships.activated',
            subject: $membership,
            before: ['status' => MembershipStatus::PendingPayment->value, 'account_status' => UserStatus::PendingPayment->value],
            after: ['status' => MembershipStatus::Active->value, 'account_status' => UserStatus::Active->value, 'activation_mode' => $mode->value],
            metadata: array_filter([
                'member_id' => $user->id,
                'payment_intent_id' => $intent?->id,
                'amount' => $membership->fee_amount,
                'transaction_fee' => $intent?->fee_amount,
                'total_paid' => $intent?->total_amount,
                'fee_setting_key' => $membership->fee_setting_key,
            ], fn (mixed $value): bool => $value !== null),
            actor: $user,
        );

        $this->notifier->notify($user, 'membership.activated');
    }
}
