<?php

namespace App\Domain\Payment\Http\Controllers;

use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Exchange\Enums\ExchangeKind;
use App\Domain\Exchange\Enums\ExchangeStatus;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Merchant\Enums\MerchantPaymentStatus;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Domain\Payment\Actions\ManualPaymentDesk;
use App\Domain\Payment\CollectionRouter;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentPurpose;
use App\Domain\Payment\Http\Resources\PaymentAttemptResource;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The single "Validations" queue of the back-office, as on the former site: memberships, packs and top-ups paid
 * outside the provider, and YEM declared as sent to the CECT. Each staff member only sees what their permissions
 * allow them to decide.
 */
class ValidationQueueController
{
    private const LIMIT = 200;

    public function __construct(private readonly ManualPaymentDesk $desk) {}

    /**
     * Items awaiting a decision ("pending"), or the recent decisions ("history").
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['nullable', 'in:pending,history']]);
        $history = ($validated['status'] ?? 'pending') === 'history';
        $user = $this->staff($request);

        $canPayments = $user->can('payments.validate_manual');
        $canYem = $user->can('yem_validations.view');
        abort_unless($canPayments || $canYem, 403);

        $items = collect()
            ->merge($canPayments ? $this->payments($history) : [])
            ->merge($canYem ? $this->yemReceipts($history) : [])
            ->merge($canYem ? $this->merchantPayments($history) : [])
            ->sortBy('date', descending: $history)
            ->values()
            ->take(self::LIMIT);

        return response()->json([
            'data' => $items,
            'meta' => ['pending_count' => $history ? null : $items->count()],
        ]);
    }

    /**
     * Validate a payment made outside the provider once the money has been received: activates what was paid for.
     */
    public function approve(Request $request, PaymentAttempt $attempt): PaymentAttemptResource
    {
        $validated = $request->validate([
            'received_amount' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:120'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        return new PaymentAttemptResource($this->desk->approve($this->staff($request), $attempt, (int) $validated['received_amount'], $validated['reference'], $validated['comment'] ?? null));
    }

    /**
     * Reject a declared payment that cannot be found.
     */
    public function reject(Request $request, PaymentAttempt $attempt): PaymentAttemptResource
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return new PaymentAttemptResource($this->desk->reject($this->staff($request), $attempt, $validated['reason']));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function payments(bool $history): array
    {
        return PaymentAttempt::query()
            ->with(['intent.user.pernumIdentities', 'intent.payable'])
            ->where('provider', CollectionRouter::MANUAL_PROVIDER)
            ->when(
                $history,
                fn ($query) => $query->whereNotNull('validated_at')->orderByDesc('validated_at'),
                fn ($query) => $query->where('status', PaymentAttemptStatus::Pending)->orderBy('created_at'),
            )
            ->limit(self::LIMIT)
            ->get()
            ->map(function (PaymentAttempt $attempt): array {
                $intent = $attempt->intent;

                return [
                    'id' => $attempt->id,
                    'kind' => 'payment',
                    'type' => $intent->purpose->value,
                    'label' => $this->paymentLabel($intent->purpose, $intent->payable),
                    'member' => $this->member($intent->user),
                    'amount_xof' => $attempt->amount,
                    'quantity' => null,
                    'method' => $attempt->method,
                    'declared_reference' => $attempt->declared_reference,
                    'declared_at' => $attempt->declared_at?->toIso8601ZuluString(),
                    'status' => match (true) {
                        $attempt->status === PaymentAttemptStatus::Pending => 'pending',
                        $attempt->status === PaymentAttemptStatus::Approved => 'validated',
                        default => 'rejected',
                    },
                    'decided_at' => $attempt->validated_at?->toIso8601ZuluString(),
                    'date' => ($attempt->validated_at ?? $attempt->declared_at ?? $attempt->created_at)->toIso8601ZuluString(),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function yemReceipts(bool $history): array
    {
        $statuses = $history
            ? [ExchangeStatus::YemReceived, ExchangeStatus::Completed, ExchangeStatus::Rejected]
            : [ExchangeStatus::DeclaredSent, ExchangeStatus::Disputed];

        return ExchangeRequest::query()
            ->with(['user.pernumIdentities'])
            ->whereIn('status', $statuses)
            ->orderBy($history ? 'validated_at' : 'created_at', $history ? 'desc' : 'asc')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (ExchangeRequest $exchange): array => [
                'id' => $exchange->id,
                'kind' => 'yem_receipt',
                'type' => 'yem_receipt',
                'label' => ($exchange->kind === ExchangeKind::MerchantRedemption ? 'Rééchange marchand ' : 'Réception YEM ').$exchange->reference,
                'member' => $this->member($exchange->user),
                'amount_xof' => $exchange->amount_due_xof,
                'quantity' => $exchange->quantity_requested,
                'method' => null,
                'declared_reference' => $exchange->member_transfer_reference,
                'declared_at' => $exchange->declared_at?->toIso8601ZuluString(),
                'status' => match ($exchange->status) {
                    ExchangeStatus::DeclaredSent => 'pending',
                    ExchangeStatus::Disputed => 'disputed',
                    ExchangeStatus::Rejected => 'rejected',
                    default => 'validated',
                },
                'decided_at' => $exchange->validated_at?->toIso8601ZuluString(),
                'date' => ($exchange->validated_at ?? $exchange->declared_at ?? $exchange->created_at)->toIso8601ZuluString(),
            ])
            ->all();
    }

    /**
     * Merchant payments awaiting a Validator: declared ones in validator mode, disputed ones in any mode.
     *
     * @return array<int, array<string, mixed>>
     */
    private function merchantPayments(bool $history): array
    {
        return MerchantPayment::query()
            ->with(['payer.pernumIdentities', 'payer.organization', 'merchant.organization'])
            ->when(
                $history,
                fn ($query) => $query->whereIn('status', [MerchantPaymentStatus::Confirmed, MerchantPaymentStatus::Rejected])
                    ->where(fn ($inner) => $inner->where('confirmation_mode', MerchantPayment::MODE_VALIDATOR)->orWhereNotNull('dispute_reason'))
                    ->orderByDesc('closed_at'),
                fn ($query) => $query->where(fn ($inner) => $inner
                    ->where('status', MerchantPaymentStatus::Disputed)
                    ->orWhere(fn ($declared) => $declared->where('status', MerchantPaymentStatus::DeclaredSent)->where('confirmation_mode', MerchantPayment::MODE_VALIDATOR)))
                    ->orderBy('created_at'),
            )
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (MerchantPayment $payment): array => [
                'id' => $payment->id,
                'kind' => 'merchant_payment',
                'type' => 'merchant_payment',
                'label' => 'Paiement marchand '.$payment->reference.' — '.($payment->merchant->organization->legal_name ?? $payment->merchant->full_name),
                'member' => $this->member($payment->payer),
                'amount_xof' => null,
                'quantity' => $payment->quantity,
                'method' => null,
                'declared_reference' => $payment->payer_transfer_reference,
                'declared_at' => $payment->declared_at?->toIso8601ZuluString(),
                'status' => match ($payment->status) {
                    MerchantPaymentStatus::Disputed => 'disputed',
                    MerchantPaymentStatus::Confirmed => 'validated',
                    MerchantPaymentStatus::Rejected => 'rejected',
                    default => 'pending',
                },
                'decided_at' => $payment->closed_at?->toIso8601ZuluString(),
                'date' => ($payment->closed_at ?? $payment->declared_at ?? $payment->created_at)->toIso8601ZuluString(),
            ])
            ->all();
    }

    private function paymentLabel(PaymentPurpose $purpose, mixed $payable): string
    {
        if ($purpose === PaymentPurpose::Membership) {
            return 'Adhésion';
        }

        if ($purpose === PaymentPurpose::PackTopup) {
            return 'Rechargement Perso';
        }

        if ($payable instanceof UserPack) {
            $payable->loadMissing('plan');
            $family = match ($payable->family) {
                PackFamily::Vendeur => 'Vendeur',
                PackFamily::Consommateur => 'Consommateur',
                PackFamily::Personnel => 'Personnel',
                PackFamily::Marchand => 'Marchand',
            };

            return "Pack {$family} {$payable->plan->code}";
        }

        return 'Paiement';
    }

    /**
     * @return array<string, mixed>
     */
    private function member(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->organization->legal_name ?? $user->full_name,
            'member_number' => $user->member_number,
            'pernum' => $user->pernumIdentities->firstWhere('status', '!=', 'revoked')?->pernum,
        ];
    }

    private function staff(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
