<?php

namespace App\Domain\Merchant\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\PernumStatus;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Merchant\Enums\MerchantPaymentStatus;
use App\Domain\Merchant\Exceptions\MerchantException;
use App\Domain\Merchant\MerchantLedger;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Domain\Notification\Notifier;
use App\Domain\Quota\Exceptions\QuotaException;
use App\Domain\Quota\QuotaLedger;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Exceptions\SubscriptionException;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use App\Support\YemQuantity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A member pays a merchant of the network with the quota/balance of a Consommateur or Personnel pack.
 *
 * Scanning the QR creates an intent and reserves the quantity atomically; no YEM moves and nothing is proven by
 * the scan (AC-13). The real transfer happens outside the platform, to the merchant's Pernum resolved by the server.
 * Only a confirmation (by the merchant or by a Validator, as configured) consumes the payer's quota and credits the
 * merchant's balance eligible for redemption.
 */
class MerchantPaymentService
{
    public function __construct(
        private readonly MerchantQrService $qrService,
        private readonly QuotaLedger $quotaLedger,
        private readonly MerchantLedger $merchantLedger,
        private readonly SettingsService $settings,
        private readonly AuditLogger $auditLogger,
        private readonly Notifier $notifier,
    ) {}

    public function create(User $payer, UserPack $payerPack, string $token, string $quantity): MerchantPayment
    {
        if (! $payer->isMember() || $payer->status !== UserStatus::Active) {
            throw SubscriptionException::memberNotActive();
        }

        $allowed = array_map('strval', $this->settings->array('merchant_payment.payer_families'));

        if ($payerPack->user_id !== $payer->id || ! in_array($payerPack->family->value, $allowed, true)) {
            throw MerchantException::packNotEligible();
        }

        try {
            $quantity = YemQuantity::of($quantity);
        } catch (InvalidArgumentException) {
            throw QuotaException::invalidQuantity();
        }

        if (! YemQuantity::isPositive($quantity)) {
            throw QuotaException::invalidQuantity();
        }

        $merchant = $this->qrService->resolve($token);

        if ($merchant['merchant']->id === $payer->id) {
            throw MerchantException::selfPayment();
        }

        $payerPernum = $payer->pernumIdentities()->whereIn('status', [PernumStatus::Declared, PernumStatus::Verified])->latest()->value('pernum');
        $mode = $this->settings->string('merchant_payment.confirmation_mode') === MerchantPayment::MODE_VALIDATOR
            ? MerchantPayment::MODE_VALIDATOR
            : MerchantPayment::MODE_MERCHANT;

        return DB::transaction(function () use ($payer, $payerPack, $quantity, $merchant, $payerPernum, $mode): MerchantPayment {
            $payment = MerchantPayment::query()->create([
                'reference' => 'MP-'.str_pad((string) DB::scalar("select nextval('merchant_payment_reference_seq')"), 7, '0', STR_PAD_LEFT),
                'payer_id' => $payer->id,
                'payer_pack_id' => $payerPack->id,
                'merchant_id' => $merchant['merchant']->id,
                'merchant_pack_id' => $merchant['pack']->id,
                'qr_code_id' => $merchant['qr']->id,
                'status' => MerchantPaymentStatus::Reserved,
                'quantity' => $quantity,
                'payer_pernum' => is_string($payerPernum) ? $payerPernum : null,
                'merchant_pernum' => $merchant['pernum'],
                'outlet_label' => $merchant['qr']->outlet_label,
                'confirmation_mode' => $mode,
                'expires_at' => now()->addMinutes($this->settings->integer('merchant_payment.reservation_ttl_minutes')),
            ]);

            $this->quotaLedger->reserve($payerPack, $quantity, "merchant_payment:{$payment->id}:reserve", $payment);

            $this->auditLogger->record('merchant_payments.created', $payment, after: [
                'reference' => $payment->reference,
                'quantity' => $quantity,
                'merchant_id' => $payment->merchant_id,
                'merchant_pernum' => $payment->merchant_pernum,
                'confirmation_mode' => $mode,
            ], actor: $payer);

            return $payment;
        });
    }

    /**
     * The payer says the YEM were sent to the merchant. Informational: nothing is consumed or credited yet.
     */
    public function declareSent(User $payer, MerchantPayment $payment, ?string $transferReference): MerchantPayment
    {
        return DB::transaction(function () use ($payer, $payment, $transferReference): MerchantPayment {
            $payment = $this->lock($payment);

            if ($payment->status === MerchantPaymentStatus::DeclaredSent) {
                return $payment;
            }

            if ($payment->status !== MerchantPaymentStatus::Reserved) {
                throw MerchantException::notOpen($payment->status->value);
            }

            $payment->forceFill(['status' => MerchantPaymentStatus::DeclaredSent, 'declared_at' => now(), 'payer_transfer_reference' => $transferReference])->save();
            $this->auditLogger->record('merchant_payments.declared_sent', $payment, before: ['status' => MerchantPaymentStatus::Reserved->value], after: ['status' => MerchantPaymentStatus::DeclaredSent->value, 'transfer_reference' => $transferReference], actor: $payer);

            if ($payment->confirmation_mode === MerchantPayment::MODE_MERCHANT) {
                $this->notifier->notify($payment->merchant, 'merchant_payment.declared', [
                    'reference' => $payment->reference,
                    'quantity' => $payment->quantity,
                    'payer' => $payer->organization->legal_name ?? $payer->full_name,
                ]);
            }

            return $payment;
        });
    }

    public function cancel(User $payer, MerchantPayment $payment): MerchantPayment
    {
        return $this->release($payment, [MerchantPaymentStatus::Reserved], MerchantPaymentStatus::Canceled, 'merchant_payments.canceled', 'Annulé par le payeur avant le transfert', $payer, notCancelable: true);
    }

    /**
     * The merchant confirms having received the YEM on its Pernum (merchant confirmation mode).
     */
    public function confirmByMerchant(User $merchant, MerchantPayment $payment): MerchantPayment
    {
        if ($payment->confirmation_mode !== MerchantPayment::MODE_MERCHANT) {
            throw MerchantException::confirmationReservedToValidator();
        }

        return $this->confirm($merchant, $payment, [MerchantPaymentStatus::Reserved, MerchantPaymentStatus::DeclaredSent], 'merchant_payments.confirmed_by_merchant');
    }

    /**
     * The merchant reports a declared payment as not received: a Validator arbitrates.
     */
    public function reportNotReceived(User $merchant, MerchantPayment $payment, string $reason): MerchantPayment
    {
        return DB::transaction(function () use ($merchant, $payment, $reason): MerchantPayment {
            $payment = $this->lock($payment);

            if ($payment->status !== MerchantPaymentStatus::DeclaredSent) {
                throw MerchantException::notOpen($payment->status->value);
            }

            $payment->forceFill(['status' => MerchantPaymentStatus::Disputed, 'dispute_reason' => $reason])->save();
            $this->auditLogger->record('merchant_payments.disputed', $payment, before: ['status' => MerchantPaymentStatus::DeclaredSent->value], after: ['status' => MerchantPaymentStatus::Disputed->value], reason: $reason, actor: $merchant);

            return $payment;
        });
    }

    /**
     * A Validator confirms the transfer: a declared payment in validator mode, or a disputed one in either mode.
     */
    public function confirmByValidator(User $validator, MerchantPayment $payment): MerchantPayment
    {
        $allowed = $payment->confirmation_mode === MerchantPayment::MODE_VALIDATOR
            ? [MerchantPaymentStatus::DeclaredSent, MerchantPaymentStatus::Disputed]
            : [MerchantPaymentStatus::Disputed];

        return $this->confirm($validator, $payment, $allowed, 'merchant_payments.confirmed_by_validator');
    }

    /**
     * A Validator finds no transfer: the payer's quota is given back.
     */
    public function rejectByValidator(User $validator, MerchantPayment $payment, string $reason): MerchantPayment
    {
        return $this->release($payment, [MerchantPaymentStatus::DeclaredSent, MerchantPaymentStatus::Disputed], MerchantPaymentStatus::Rejected, 'merchant_payments.rejected', $reason, $validator);
    }

    /**
     * Payments never declared within the reservation delay give their quota back.
     */
    public function expireStale(): int
    {
        $expired = 0;

        MerchantPayment::query()
            ->where('status', MerchantPaymentStatus::Reserved)
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($payments) use (&$expired): void {
                foreach ($payments as $payment) {
                    try {
                        $this->release($payment, [MerchantPaymentStatus::Reserved], MerchantPaymentStatus::Expired, 'merchant_payments.expired', 'Délai de réservation dépassé sans transfert');
                        $expired++;
                    } catch (MerchantException) {
                        // Declared or confirmed in the meantime.
                    }
                }
            });

        return $expired;
    }

    /**
     * @param  list<MerchantPaymentStatus>  $from
     */
    private function confirm(User $actor, MerchantPayment $payment, array $from, string $action): MerchantPayment
    {
        return DB::transaction(function () use ($actor, $payment, $from, $action): MerchantPayment {
            $payment = $this->lock($payment);

            if ($payment->status === MerchantPaymentStatus::Confirmed) {
                return $payment;
            }

            if (! in_array($payment->status, $from, true)) {
                throw MerchantException::notOpen($payment->status->value);
            }

            $before = $payment->status;

            $this->quotaLedger->consume($payment->payerPack, $payment->quantity, "merchant_payment:{$payment->id}:consume", $payment);
            $this->merchantLedger->credit($payment->merchantPack, $payment->quantity, "merchant_payment:{$payment->id}:credit", $payment, "Encaissement {$payment->reference}");

            $payment->forceFill([
                'status' => MerchantPaymentStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmed_by' => $actor->id,
                'closed_at' => now(),
            ])->save();

            $this->auditLogger->record($action, $payment, before: ['status' => $before->value], after: ['status' => MerchantPaymentStatus::Confirmed->value, 'quantity' => $payment->quantity], actor: $actor);
            $this->notifier->notify($payment->payer, 'merchant_payment.confirmed', [
                'reference' => $payment->reference,
                'quantity' => $payment->quantity,
                'merchant' => $payment->merchant->organization->legal_name ?? $payment->merchant->full_name,
            ]);

            return $payment;
        });
    }

    /**
     * @param  list<MerchantPaymentStatus>  $from
     */
    private function release(MerchantPayment $payment, array $from, MerchantPaymentStatus $target, string $action, string $reason, ?User $actor = null, bool $notCancelable = false): MerchantPayment
    {
        return DB::transaction(function () use ($payment, $from, $target, $action, $reason, $actor, $notCancelable): MerchantPayment {
            $payment = $this->lock($payment);

            if (! in_array($payment->status, $from, true)) {
                throw $notCancelable ? MerchantException::notCancelable($payment->status->value) : MerchantException::notOpen($payment->status->value);
            }

            $before = $payment->status;

            $this->quotaLedger->release($payment->payerPack, $payment->quantity, "merchant_payment:{$payment->id}:release", $payment, $reason);

            $payment->forceFill(['status' => $target, 'closed_at' => now()])->save();
            $this->auditLogger->record($action, $payment, before: ['status' => $before->value], after: ['status' => $target->value], reason: $reason, actor: $actor);

            if ($target === MerchantPaymentStatus::Rejected) {
                $this->notifier->notify($payment->payer, 'merchant_payment.rejected', ['reference' => $payment->reference, 'reason' => $reason]);
            }

            return $payment;
        });
    }

    private function lock(MerchantPayment $payment): MerchantPayment
    {
        return MerchantPayment::query()->with(['payerPack', 'merchantPack'])->lockForUpdate()->findOrFail($payment->id);
    }
}
