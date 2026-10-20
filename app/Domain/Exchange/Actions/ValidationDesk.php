<?php

namespace App\Domain\Exchange\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exchange\Enums\ExchangeStatus;
use App\Domain\Exchange\Enums\ValidationAction;
use App\Domain\Exchange\Exceptions\ExchangeException;
use App\Domain\Exchange\ExchangeReservations;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Exchange\Enums\ExchangeKind;
use App\Domain\Merchant\MerchantRedemptionPricing;
use App\Domain\Notification\Notifier;
use App\Domain\Payout\PayoutPlanner;
use App\Domain\Quota\Exceptions\QuotaException;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use App\Support\YemQuantity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The Validator's decisions on YEM sent to the CECT Pernum.
 *
 * Confirming is the only way to make a counter-value payable: in one transaction it consumes the quantity really
 * received, gives back any unreceived remainder, computes the FCFA debt with the rate snapshotted at request time,
 * records the evidence and creates the payout. The Validator never sets an amount, a beneficiary or a payment status.
 */
class ValidationDesk
{
    public function __construct(
        private readonly ExchangeReservations $reservations,
        private readonly PayoutPlanner $payouts,
        private readonly SettingsService $settings,
        private readonly AuditLogger $auditLogger,
        private readonly Notifier $notifier,
    ) {}

    public function confirm(User $validator, ExchangeRequest $exchange, string $quantityReceived, ?string $externalReference, ?string $comment): ExchangeRequest
    {
        if ($this->settings->boolean('validation.require_external_reference') && ($externalReference === null || trim($externalReference) === '')) {
            throw ExchangeException::externalReferenceRequired();
        }

        try {
            $received = YemQuantity::of($quantityReceived);
        } catch (InvalidArgumentException) {
            throw QuotaException::invalidQuantity();
        }

        if (! YemQuantity::isPositive($received)) {
            throw QuotaException::invalidQuantity();
        }

        return DB::transaction(function () use ($validator, $exchange, $received, $externalReference, $comment): ExchangeRequest {
            $exchange = $this->lockOpen($exchange);

            if (YemQuantity::compare($received, $exchange->quantity_requested) > 0) {
                throw ExchangeException::receivedExceedsRequested($exchange->quantity_requested);
            }

            $before = $exchange->status;

            $this->reservations->consume($exchange, $received);

            $remainder = YemQuantity::sub($exchange->quantity_requested, $received);

            if (YemQuantity::isPositive($remainder)) {
                $this->reservations->release($exchange, $remainder, 'Quantité reçue inférieure à la quantité demandée');
            }

            // Counter-value truncated to the franc: the cooperative never pays a fraction of a franc it does not owe.
            $merchantBreakdown = $exchange->kind === ExchangeKind::MerchantRedemption
                ? MerchantRedemptionPricing::forExchange($exchange, $received) : null;
            $amount = $merchantBreakdown !== null
                ? $merchantBreakdown['net_amount_xof']
                : YemQuantity::toXof($received, $exchange->rate_xof_per_yem);

            $exchange->forceFill([
                'status' => $amount > 0 ? ExchangeStatus::YemReceived : ExchangeStatus::Completed,
                'quantity_received' => $received,
                'amount_due_xof' => $amount,
                'validated_at' => now(),
                'validated_by' => $validator->id,
                'closed_at' => $amount > 0 ? null : now(),
            ])->save();

            $this->recordEvent($exchange, $validator, ValidationAction::Confirmed, $received, $externalReference, $comment);

            if ($amount > 0) {
                $this->payouts->plan($exchange, $exchange->user, $amount, $exchange->payout_destination);
            }

            $this->auditLogger->record('exchanges.yem_received', $exchange, before: ['status' => $before->value], after: [
                'status' => $exchange->status->value,
                'quantity_requested' => $exchange->quantity_requested,
                'quantity_received' => $received,
                'rate_xof_per_yem' => $exchange->rate_xof_per_yem,
                'merchant_pricing' => $merchantBreakdown,
                'amount_due_xof' => $amount,
                'external_reference' => $externalReference,
            ], actor: $validator);

            $this->notifier->notify($exchange->user, 'exchange.yem_received', [
                'reference' => $exchange->reference,
                'quantity' => $received,
                'amount' => number_format($amount, 0, ',', ' '),
            ]);

            return $exchange;
        });
    }

    public function reject(User $validator, ExchangeRequest $exchange, string $reason): ExchangeRequest
    {
        return DB::transaction(function () use ($validator, $exchange, $reason): ExchangeRequest {
            $exchange = $this->lockOpen($exchange);
            $before = $exchange->status;

            $this->reservations->release($exchange, $exchange->quantity_requested, "Rejet du Validateur : {$reason}");

            $exchange->forceFill(['status' => ExchangeStatus::Rejected, 'validated_at' => now(), 'validated_by' => $validator->id, 'closed_at' => now()])->save();
            $this->recordEvent($exchange, $validator, ValidationAction::Rejected, null, null, $reason);

            $this->auditLogger->record('exchanges.rejected', $exchange, before: ['status' => $before->value], after: ['status' => ExchangeStatus::Rejected->value], reason: $reason, actor: $validator);
            $this->notifier->notify($exchange->user, 'exchange.rejected', ['reference' => $exchange->reference, 'reason' => $reason]);

            return $exchange;
        });
    }

    /**
     * Places a request in dispute: the quota stays reserved until the Validator confirms or rejects it.
     */
    public function dispute(User $validator, ExchangeRequest $exchange, string $comment): ExchangeRequest
    {
        return DB::transaction(function () use ($validator, $exchange, $comment): ExchangeRequest {
            $exchange = $this->lockOpen($exchange);

            if ($exchange->status === ExchangeStatus::Disputed) {
                throw ExchangeException::notOpen($exchange->status->value);
            }

            $before = $exchange->status;
            $exchange->forceFill(['status' => ExchangeStatus::Disputed])->save();
            $this->recordEvent($exchange, $validator, ValidationAction::Disputed, null, null, $comment);

            $this->auditLogger->record('exchanges.disputed', $exchange, before: ['status' => $before->value], after: ['status' => ExchangeStatus::Disputed->value], reason: $comment, actor: $validator);

            return $exchange;
        });
    }

    private function lockOpen(ExchangeRequest $exchange): ExchangeRequest
    {
        $exchange = ExchangeRequest::query()->with(['userPack', 'user'])->lockForUpdate()->findOrFail($exchange->id);

        if (! $exchange->status->isOpen()) {
            throw ExchangeException::notOpen($exchange->status->value);
        }

        return $exchange;
    }

    private function recordEvent(ExchangeRequest $exchange, User $validator, ValidationAction $action, ?string $quantity, ?string $externalReference, ?string $comment): void
    {
        $exchange->validationEvents()->create([
            'action' => $action,
            'quantity_received' => $quantity,
            'external_reference' => $externalReference,
            'comment' => $comment,
            'actor_id' => $validator->id,
            'created_at' => now(),
        ]);
    }
}
