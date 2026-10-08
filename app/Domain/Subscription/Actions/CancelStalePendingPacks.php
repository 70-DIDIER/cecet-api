<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentIntentStatus;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\PaymentCheckout;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Domain\Subscription\Models\UserPack;
use Illuminate\Support\Facades\DB;

/**
 * Cancels packs that were subscribed but never paid, freeing the member's slot.
 * A pack with a payment still in flight is left alone until that attempt ends.
 */
class CancelStalePendingPacks
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly PaymentCheckout $checkout,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(): int
    {
        $cutoff = now()->subHours($this->settings->integer('packs.pending_payment_ttl_hours'));
        $canceled = 0;

        UserPack::query()
            ->where('status', UserPackStatus::PendingPayment)
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($packs) use (&$canceled): void {
                foreach ($packs as $pack) {
                    $canceled += (int) $this->cancel($pack);
                }
            });

        return $canceled;
    }

    private function cancel(UserPack $pack): bool
    {
        return DB::transaction(function () use ($pack): bool {
            $pack = UserPack::query()->lockForUpdate()->findOrFail($pack->id);

            $this->checkout->expireStale($pack);

            $hasInFlightPayment = PaymentAttempt::query()
                ->whereIn('payment_intent_id', $pack->paymentIntents()->select('id'))
                ->whereIn('status', [PaymentAttemptStatus::Created, PaymentAttemptStatus::Pending])
                ->exists();

            if ($pack->status !== UserPackStatus::PendingPayment || $hasInFlightPayment) {
                return false;
            }

            $pack->paymentIntents()->where('status', PaymentIntentStatus::Open)->update([
                'status' => PaymentIntentStatus::Canceled->value,
                'canceled_at' => now(),
            ]);
            $pack->forceFill(['status' => UserPackStatus::Canceled, 'canceled_at' => now()])->save();

            $this->auditLogger->record(
                action: 'packs.canceled_unpaid',
                subject: $pack,
                before: ['status' => UserPackStatus::PendingPayment->value],
                after: ['status' => UserPackStatus::Canceled->value],
                reason: 'Pack non payé dans le délai imparti.',
            );

            return true;
        });
    }
}
