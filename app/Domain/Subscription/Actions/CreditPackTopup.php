<?php

namespace App\Domain\Subscription\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Notification\Notifier;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Quota\QuotaLedger;
use App\Domain\Subscription\Enums\PackTopupStatus;
use App\Domain\Subscription\Models\PackTopup;
use App\Support\YemQuantity;

/**
 * Credits the Personnel pack with the YEM bought by a top-up once its payment is settled. Must run inside the
 * settling transaction; idempotent (one ledger entry per top-up).
 */
class CreditPackTopup
{
    public function __construct(
        private readonly QuotaLedger $ledger,
        private readonly AuditLogger $auditLogger,
        private readonly Notifier $notifier,
    ) {}

    public function handle(PackTopup $topup, PaymentIntent $intent): PackTopup
    {
        $topup = PackTopup::query()->with(['userPack', 'user'])->lockForUpdate()->findOrFail($topup->id);

        if ($topup->status !== PackTopupStatus::PendingPayment) {
            return $topup;
        }

        $this->ledger->credit($topup->userPack, $topup->yem_base, "topup:{$topup->id}", reason: 'Rechargement Perso');

        if (YemQuantity::isPositive($topup->yem_cashback)) {
            $this->ledger->creditCashback($topup->userPack, $topup->yem_cashback, "topup:{$topup->id}:cashback", reason: 'Cashback rechargement Perso');
        }

        $topup->forceFill(['status' => PackTopupStatus::Credited, 'credited_at' => now()])->save();

        $this->auditLogger->record(
            action: 'packs.topup_credited',
            subject: $topup,
            before: ['status' => PackTopupStatus::PendingPayment->value],
            after: ['status' => PackTopupStatus::Credited->value, 'quantity' => $topup->quantity, 'yem_base' => $topup->yem_base, 'yem_cashback' => $topup->yem_cashback],
            metadata: [
                'user_pack_id' => $topup->user_pack_id,
                'amount_xof' => $topup->amount_xof,
                'transaction_fee' => $intent->fee_amount,
                'total_paid' => $intent->total_amount,
                'payment_intent_id' => $intent->id,
            ],
            actor: $topup->user,
        );

        $this->notifier->notify($topup->user, 'topup.credited', ['amount' => number_format($topup->amount_xof, 0, ',', ' '), 'quantity' => $topup->quantity]);

        return $topup;
    }
}
