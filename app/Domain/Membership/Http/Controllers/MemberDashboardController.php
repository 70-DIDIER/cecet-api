<?php

namespace App\Domain\Membership\Http\Controllers;

use App\Domain\Membership\Models\Membership;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Http\Resources\PaymentAttemptResource;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Quota\Enums\LedgerEntryType;
use App\Domain\Quota\Models\QuotaLedgerEntry;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use App\Support\YemQuantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The member area home page in one call: profile, membership, YEM balance and totals, active packs, the latest
 * movements with a readable label, and the payments still awaiting an action.
 */
class MemberDashboardController
{
    private const RECENT_MOVEMENTS = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $membership = Membership::query()->where('user_id', $user->id)->first();
        $packIds = UserPack::query()->where('user_id', $user->id)->select('id');

        $packs = UserPack::query()
            ->where('user_id', $user->id)
            ->where('status', UserPackStatus::Active)
            ->with(['plan', 'version', 'account'])
            ->orderBy('activated_at')
            ->get();

        $totals = QuotaLedgerEntry::query()
            ->whereIn('user_pack_id', $packIds)
            ->selectRaw("COALESCE(SUM(available_delta) FILTER (WHERE type = 'credit' OR (type = 'adjust' AND available_delta > 0)), 0) AS credited")
            ->selectRaw("COALESCE(SUM(consumed_delta) FILTER (WHERE type = 'consume'), 0) AS spent")
            ->first();

        $movements = QuotaLedgerEntry::query()
            ->whereIn('user_pack_id', $packIds)
            ->with(['userPack.plan', 'period'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_MOVEMENTS)
            ->get();

        $awaiting = PaymentAttempt::query()
            ->whereIn('payment_intent_id', PaymentIntent::query()->where('user_id', $user->id)->select('id'))
            ->where('status', PaymentAttemptStatus::Pending)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => [
            'profile' => [
                'name' => $user->organization->legal_name ?? $user->full_name,
                'member_number' => $user->member_number,
                'pernum' => $user->pernumIdentities()->where('status', '!=', 'revoked')->value('pernum'),
                'email' => $user->email,
                'phone' => $user->phone,
                'city' => $user->city,
                'person_type' => $user->person_type?->value,
                'status' => $user->status->value,
            ],
            'membership' => $membership === null ? null : [
                'status' => $membership->status->value,
                'paid' => $membership->paid_at !== null || $membership->activated_at !== null,
                'member_since' => $membership->activated_at?->toIso8601ZuluString(),
            ],
            'yem' => [
                'balance' => YemQuantity::of($packs->reduce(fn (string $sum, UserPack $pack): string => YemQuantity::add($sum, $pack->account->available ?? '0'), '0')),
                'reserved' => YemQuantity::of($packs->reduce(fn (string $sum, UserPack $pack): string => YemQuantity::add($sum, $pack->account->reserved ?? '0'), '0')),
                'total_credited' => YemQuantity::of((string) ($totals?->getAttribute('credited') ?? '0')),
                'total_spent' => YemQuantity::of((string) ($totals?->getAttribute('spent') ?? '0')),
            ],
            'active_packs' => $packs->map(fn (UserPack $pack): array => [
                'id' => $pack->id,
                'family' => $pack->family->value,
                'code' => $pack->plan->code,
                'name' => $pack->plan->name,
                'price_xof' => $pack->price_xof,
                'quota_per_period' => $pack->version->quota_per_period,
                'discount_bps' => is_int($pack->version->rules['discount_bps'] ?? null) ? $pack->version->rules['discount_bps'] : null,
                'available' => $pack->account?->available,
                'activated_at' => $pack->activated_at?->toIso8601ZuluString(),
                'expires_at' => $pack->expires_at?->toIso8601ZuluString(),
            ])->all(),
            'recent_movements' => $movements->map(fn (QuotaLedgerEntry $entry): array => [
                'id' => $entry->id,
                'date' => $entry->created_at->toIso8601ZuluString(),
                'label' => $this->label($entry),
                'type' => $entry->type->value,
                'direction' => YemQuantity::compare($entry->available_delta, '0') > 0 ? 'credit' : (YemQuantity::compare($entry->consumed_delta, '0') > 0 || YemQuantity::compare($entry->available_delta, '0') < 0 ? 'debit' : 'neutral'),
                'quantity' => $this->magnitude($entry),
                'user_pack_id' => $entry->user_pack_id,
            ])->all(),
            'payments_awaiting_action' => PaymentAttemptResource::collection($awaiting)->resolve($request),
        ]]);
    }

    private function label(QuotaLedgerEntry $entry): string
    {
        $code = $entry->userPack->plan->code;

        return match ($entry->type) {
            LedgerEntryType::Credit => $entry->period !== null ? "Crédit période {$entry->period->number} — {$code}" : ($entry->reason ?? "Crédit {$code}"),
            LedgerEntryType::Reserve => "Réservation pour une vente de YEM — {$code}",
            LedgerEntryType::Release => "Réservation libérée — {$code}",
            LedgerEntryType::Consume => "Vente de YEM confirmée — {$code}",
            LedgerEntryType::Expire => "Quota expiré — {$code}",
            LedgerEntryType::Adjust => 'Ajustement : '.($entry->reason ?? $code),
        };
    }

    private function magnitude(QuotaLedgerEntry $entry): string
    {
        $delta = $entry->type === LedgerEntryType::Consume ? $entry->consumed_delta : $entry->available_delta;

        return YemQuantity::compare($delta, '0') < 0 ? YemQuantity::negate($delta) : YemQuantity::of($delta);
    }
}
