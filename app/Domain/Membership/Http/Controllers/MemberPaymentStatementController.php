<?php

namespace App\Domain\Membership\Http\Controllers;

use App\Domain\Membership\Models\Membership;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentIntentStatus;
use App\Domain\Payment\Enums\PaymentPurpose;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Subscription\Models\PackTopup;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only, member-scoped statement. The monetary truth comes from settled
 * PaymentIntents; a pack's "active" status alone never proves an amount was collected.
 *
 * Periodic YEM allowances are intentionally absent from this FIAT statement:
 * they are available from /me/packs/{id} (contractual pack periods).
 */
class MemberPaymentStatementController
{
    public function __invoke(Request $request): JsonResponse
    {
        $member = $request->user();
        abort_unless($member instanceof User && $member->isMember(), 403);

        $membership = Membership::query()->where('user_id', $member->id)->first();
        $packs = UserPack::query()->where('user_id', $member->id)->with('plan')->get();
        $topups = PackTopup::query()->where('user_id', $member->id)->with('userPack.plan')->get();
        $intents = PaymentIntent::query()
            ->where('user_id', $member->id)
            ->whereIn('purpose', [
                PaymentPurpose::Membership,
                PaymentPurpose::PackSubscription,
                PaymentPurpose::PackTopup,
            ])
            ->with('attempts')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $rows = collect();

        if ($membership !== null) {
            $rows->push($this->row(
                $intents,
                PaymentPurpose::Membership,
                $membership->id,
                'membership',
                'Adhésion CECT',
                $membership->fee_amount,
                $membership->created_at?->toIso8601ZuluString(),
                $membership->activated_at !== null,
            ));
        }

        foreach ($packs as $pack) {
            $rows->push($this->row(
                $intents,
                PaymentPurpose::PackSubscription,
                $pack->id,
                'pack',
                $pack->plan->name,
                $pack->price_xof,
                $pack->created_at?->toIso8601ZuluString(),
                $pack->activated_at !== null,
                $pack->id,
                $pack->family->value,
            ));
        }

        foreach ($topups as $topup) {
            $rows->push($this->row(
                $intents,
                PaymentPurpose::PackTopup,
                $topup->id,
                'topup',
                'Rechargement · '.$topup->userPack->plan->name,
                $topup->amount_xof,
                $topup->created_at?->toIso8601ZuluString(),
                $topup->credited_at !== null,
                $topup->user_pack_id,
                $topup->userPack->family->value,
            ));
        }

        $rows = $rows->sortByDesc(fn (array $row): string => $row['created_at'] ?? '')->values();
        $paid = $rows->where('status', 'paid');
        $awaiting = $rows->where('status', 'pending');

        return response()->json(['data' => [
            'person_type' => $member->person_type?->value,
            'currency' => 'XOF',
            'payment_mode' => 'upfront_once',
            'installments_allowed' => false,
            'summary' => [
                'confirmed_payments_count' => $paid->count(),
                'pending_payments_count' => $awaiting->count(),
                'confirmed_total_xof' => $paid->sum('total_amount_xof'),
                'confirmed_contract_total_xof' => $paid->sum('contract_amount_xof'),
                'confirmed_fees_xof' => $paid->sum('fee_amount_xof'),
            ],
            'payments' => $rows->all(),
        ]])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Prefer a confirmed intent for the same payable over more recent unsuccessful retries.
     * An amount is counted as "paid" only after PaymentIntentStatus::Succeeded.
     *
     * @param  Collection<int, PaymentIntent>  $intents
     * @return array<string, mixed>
     */
    private function row(
        Collection $intents,
        PaymentPurpose $purpose,
        string $subjectId,
        string $type,
        string $label,
        int $contractAmount,
        ?string $createdAt,
        bool $activated,
        ?string $packId = null,
        ?string $family = null,
    ): array {
        $matching = $intents->filter(fn (PaymentIntent $intent): bool =>
            $intent->purpose === $purpose && $intent->payable_id === $subjectId);

        $intent = $matching->first(fn (PaymentIntent $item): bool => $item->status === PaymentIntentStatus::Succeeded)
            ?? $matching->first();

        $isPaid = $intent?->status === PaymentIntentStatus::Succeeded;
        $isFree = $contractAmount === 0 && $activated && $intent === null;
        $status = $isPaid ? 'paid'
            : ($isFree ? 'free'
                : ($intent?->status === PaymentIntentStatus::Canceled ? 'canceled' : 'pending'));

        $attempt = $intent?->attempts->first(fn ($item): bool => $item->status === PaymentAttemptStatus::Approved)
            ?? $intent?->attempts->sortByDesc('created_at')->first();

        return [
            'id' => $type.':'.$subjectId,
            'type' => $type,
            'label' => $label,
            'pack_id' => $packId,
            'family' => $family,
            'contract_amount_xof' => $contractAmount,
            'fee_amount_xof' => $intent?->fee_amount ?? 0,
            'total_amount_xof' => $intent?->total_amount ?? $contractAmount,
            'status' => $status,
            'payment_intent_id' => $intent?->id,
            'payment_method' => $attempt?->method,
            'provider' => $attempt?->provider,
            'provider_reference' => $attempt?->provider_reference,
            'created_at' => $createdAt,
            'paid_at' => $isPaid ? $intent->succeeded_at?->toIso8601ZuluString() : null,
        ];
    }
}
