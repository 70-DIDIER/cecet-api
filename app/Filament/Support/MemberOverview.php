<?php

namespace App\Filament\Support;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Backoffice\Support\Format;
use App\Domain\Backoffice\Support\Labels;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;

/**
 * What the back-office shows on the page of a member: packs, payments, YEM sales, merchant payments and the audit trail,
 * as plain rows ready to display.
 */
final class MemberOverview
{
    /**
     * @return array<int, array<string, string>>
     */
    public static function packs(User $member): array
    {
        return UserPack::query()->where('user_id', $member->id)->with(['plan', 'account'])->orderByDesc('created_at')->get()
            ->map(fn (UserPack $pack): array => [
                'name' => $pack->plan->name,
                'family' => Labels::family($pack->family->value),
                'status' => Labels::label('pack', $pack->status->value),
                'balance' => $pack->account === null ? '—' : Format::yem($pack->account->available),
                'since' => Format::date($pack->activated_at),
            ])->values()->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function payments(User $member): array
    {
        $intentIds = PaymentIntent::query()->where('user_id', $member->id)->select('id');

        return PaymentAttempt::query()->whereIn('payment_intent_id', $intentIds)->orderByDesc('created_at')->limit(10)->get()
            ->map(fn (PaymentAttempt $attempt): array => [
                'reference' => 'PAY-'.strtoupper(substr($attempt->id, -8)),
                'method' => (string) ($attempt->method ?? '—'),
                'amount' => Format::xof($attempt->amount),
                'status' => Labels::label('payment', $attempt->status->value),
                'date' => Format::dateTime($attempt->created_at),
            ])->values()->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function exchanges(User $member): array
    {
        return ExchangeRequest::query()->where('user_id', $member->id)->orderByDesc('created_at')->limit(10)->get()
            ->map(fn (ExchangeRequest $exchange): array => [
                'reference' => $exchange->reference,
                'quantity' => Format::yem($exchange->quantity_requested),
                'status' => Labels::label('exchange', $exchange->status->value),
                'date' => Format::dateTime($exchange->created_at),
            ])->values()->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function merchantPayments(User $member): array
    {
        return MerchantPayment::query()
            ->where(fn ($query) => $query->where('payer_id', $member->id)->orWhere('merchant_id', $member->id))
            ->orderByDesc('created_at')->limit(10)->get()
            ->map(fn (MerchantPayment $payment): array => [
                'reference' => $payment->reference,
                'role' => $payment->payer_id === $member->id ? 'Payeur' : 'Marchand',
                'quantity' => Format::yem($payment->quantity),
                'status' => Labels::label('merchant_payment', $payment->status->value),
                'date' => Format::dateTime($payment->created_at),
            ])->values()->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function events(User $member): array
    {
        return AuditEvent::query()->where('subject_type', $member->getMorphClass())->where('subject_id', $member->id)->orderByDesc('position')->limit(10)->get()
            ->map(fn (AuditEvent $event): array => [
                'action' => $event->action,
                'reason' => (string) ($event->reason ?? ''),
                'date' => Format::dateTime($event->occurred_at),
            ])->values()->all();
    }
}
