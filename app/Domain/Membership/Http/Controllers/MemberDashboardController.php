<?php

namespace App\Domain\Membership\Http\Controllers;

use App\Domain\Exchange\Enums\ExchangeStatus;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Membership\Models\Membership;
use App\Domain\Merchant\Enums\MerchantPaymentStatus;
use App\Domain\Merchant\Models\MerchantPayment;
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
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            ->with(['plan', 'version', 'account', 'periods'])
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
                'reserved' => $pack->account?->reserved,
                'consumed' => $pack->account?->consumed,
                'period' => $this->currentPeriod($pack),
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
            'monthly_activity' => $this->monthlyActivity($packIds),
            'operations' => $this->openOperations($user),
            'payments_awaiting_action' => PaymentAttemptResource::collection($awaiting)->resolve($request),
        ]]);
    }

    /**
     * @return array{number: int, cycles: int, starts_at: string, ends_at: string, next_credit_at: string}|null
     */
    private function currentPeriod(UserPack $pack): ?array
    {
        $periods = $pack->periods;
        $now = now();
        $current = $periods->first(fn ($period): bool => $period->starts_at <= $now && $now < $period->ends_at) ?? $periods->last();

        if ($current === null) {
            return null;
        }

        $upcoming = $periods->first(fn ($period): bool => $period->starts_at > $now);

        return [
            'number' => $current->number,
            'cycles' => $periods->count(),
            'starts_at' => $current->starts_at->toIso8601ZuluString(),
            'ends_at' => $current->ends_at->toIso8601ZuluString(),
            'next_credit_at' => ($upcoming === null ? $current->ends_at : $upcoming->starts_at)->toIso8601ZuluString(),
        ];
    }

    /**
     * Quantities credited and spent per calendar month over the last six months.
     *
     * @param  Builder<UserPack>  $packIds
     * @return list<array{month: string, credit: string, debit: string}>
     */
    private function monthlyActivity($packIds): array
    {
        $first = CarbonImmutable::now()->startOfMonth()->subMonths(5);

        $rows = QuotaLedgerEntry::query()
            ->whereIn('user_pack_id', $packIds)
            ->where('created_at', '>=', $first)
            ->selectRaw("to_char(created_at, 'YYYY-MM') AS month")
            ->selectRaw("COALESCE(SUM(available_delta) FILTER (WHERE type = 'credit'), 0) AS credit")
            ->selectRaw("COALESCE(SUM(consumed_delta) FILTER (WHERE type = 'consume'), 0) AS debit")
            ->groupBy(DB::raw("to_char(created_at, 'YYYY-MM')"))
            ->get()
            ->keyBy(fn ($row): string => (string) $row->getAttribute('month'));

        return array_values(collect(range(0, 5))->map(function (int $offset) use ($first, $rows): array {
            $month = $first->addMonths($offset)->format('Y-m');
            $row = $rows->get($month);

            return [
                'month' => $month,
                'credit' => YemQuantity::of((string) ($row?->getAttribute('credit') ?? '0')),
                'debit' => YemQuantity::of((string) ($row?->getAttribute('debit') ?? '0')),
            ];
        })->all());
    }

    /**
     * YEM sales and merchant payments of the member that are not finished yet.
     *
     * @return list<array<string, mixed>>
     */
    private function openOperations(User $user): array
    {
        $exchanges = ExchangeRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [ExchangeStatus::AwaitingTransfer, ExchangeStatus::DeclaredSent, ExchangeStatus::YemReceived])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (ExchangeRequest $exchange): array => [
                'type' => 'exchange',
                'id' => $exchange->id,
                'reference' => $exchange->reference,
                'kind' => $exchange->kind->value,
                'status' => $exchange->status->value,
                'quantity' => $exchange->quantity_requested,
                'label' => null,
                'created_at' => $exchange->created_at->toIso8601ZuluString(),
            ]);

        $payments = MerchantPayment::query()
            ->where('payer_id', $user->id)
            ->whereIn('status', [MerchantPaymentStatus::Reserved, MerchantPaymentStatus::DeclaredSent])
            ->with('merchant.organization')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (MerchantPayment $payment): array => [
                'type' => 'merchant_payment',
                'id' => $payment->id,
                'reference' => $payment->reference,
                'kind' => 'merchant_payment',
                'status' => $payment->status->value,
                'quantity' => $payment->quantity,
                'label' => $payment->merchant->organization->legal_name ?? $payment->merchant->full_name,
                'created_at' => $payment->created_at->toIso8601ZuluString(),
            ]);

        return array_values($exchanges->concat($payments)->sortByDesc('created_at')->all());
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
