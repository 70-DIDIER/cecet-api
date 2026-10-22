<?php

namespace App\Domain\Subscription\Http\Controllers;

use App\Domain\Catalog\Enums\PackPlanStatus;
use App\Domain\Catalog\Http\Resources\PackPlanResource;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Payment\Http\Resources\PaymentAttemptResource;
use App\Domain\Payment\PaymentFeeCalculator;
use App\Domain\Quota\Models\QuotaLedgerEntry;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Subscription\Actions\RequestPackTopup;
use App\Domain\Subscription\Actions\SubscribeToPack;
use App\Domain\Subscription\Http\Resources\PackTopupResource;
use App\Domain\Subscription\Http\Resources\UserPackResource;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The pack catalog as seen by a member, and the packs the member holds.
 */
class MemberPackController
{
    /**
     * Published packs. Public: for a signed-in member, each pack also carries the price to pay (transaction fees
     * included) and whether the member is eligible.
     */
    public function catalog(Request $request, PaymentFeeCalculator $fees, SettingsService $settings): JsonResponse
    {
        $viewer = $request->user('sanctum');
        $user = $viewer instanceof User && $viewer->isMember() ? $viewer : null;

        return $this->catalogResponse($user, $fees, $settings);
    }

    /**
     * Member-specific catalog. Unlike the public catalog, this endpoint is behind auth:sanctum and only
     * returns packs whose published version explicitly allows the member's person type.
     */
    public function memberCatalog(Request $request, PaymentFeeCalculator $fees, SettingsService $settings): JsonResponse
    {
        return $this->catalogResponse($this->user($request), $fees, $settings);
    }

    private function catalogResponse(?User $user, PaymentFeeCalculator $fees, SettingsService $settings): JsonResponse
    {
        $providerName = $settings->string('payment.inbound_provider') ?? '';

        $plans = PackPlan::query()
            ->with('currentVersion')
            ->where('status', PackPlanStatus::Published)
            ->when(! app()->environment(['staging', 'testing']), fn ($query) => $query->where('code', 'not like', 'TST-%'))
            ->whereHas('currentVersion')
            ->when($user?->person_type !== null, fn ($query) => $query
                ->whereIn('family', $user->person_type->value === 'legal' ? ['MARCHAND'] : ['VENDEUR', 'CONSOMMATEUR', 'PERSONNEL'])
                ->whereHas('currentVersion',
                    fn ($versionQuery) => $versionQuery->whereJsonContains('eligible_person_types', $user->person_type->value),
                ))
            ->orderBy('family')
            ->orderBy('level')
            ->orderBy('code')
            ->get();

        $data = $plans->map(function (PackPlan $plan) use ($user, $fees, $providerName): array {
            $version = $plan->currentVersion;
            $quote = null;

            if ($version !== null && $user !== null) {
                try {
                    $breakdown = $fees->forNet($providerName, $version->price_xof);
                    $quote = ['net_amount' => $breakdown->net, 'fee_amount' => $breakdown->fee, 'total_amount' => $breakdown->total];
                } catch (ApiException) {
                    // Fees not configured for the active provider: no quote is offered.
                }
            }

            return [
                ...(new PackPlanResource($plan))->resolve(),
                'eligible' => $user === null || $version === null ? null : in_array($user->person_type?->value, $plan->family->defaultEligiblePersonTypes(), true) && in_array($user->person_type?->value, $version->eligible_person_types, true),
                'payment_quote' => $quote,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function showPlan(Request $request, PackPlan $plan): PackPlanResource
    {
        abort_unless($plan->status === PackPlanStatus::Published, 404);
        $plan->load('currentVersion');

        $viewer = $request->user('sanctum');
        if ($viewer instanceof User && $viewer->isMember()) {
            $version = $plan->currentVersion;
            abort_unless($version !== null && in_array($viewer->person_type?->value, $plan->family->defaultEligiblePersonTypes(), true) && in_array($viewer->person_type?->value, $version->eligible_person_types, true), 404);
        }

        return new PackPlanResource($plan);
    }

    /**
     * Packs held by the member, with their balance.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $packs = UserPack::query()
            ->where('user_id', $this->user($request)->id)
            ->with(['plan', 'version', 'account'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return UserPackResource::collection($packs);
    }

    public function show(Request $request, string $userPack): UserPackResource
    {
        return new UserPackResource($this->owned($request, $userPack)->load(['plan', 'version', 'account', 'periods']));
    }

    /**
     * Subscribe to a published pack.
     *
     * Returns the pack and, unless it is free, the hosted checkout to pay (201). A subscription already awaiting
     * payment returns its checkout as is (200). The pack is activated only when the provider confirms the payment.
     */
    public function subscribe(Request $request, SubscribeToPack $subscribe): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'string', 'ulid', Rule::exists('pack_plans', 'id')],
            'method' => ['nullable', 'string', 'max:30'],
        ]);

        $result = $subscribe->handle($this->user($request), PackPlan::query()->whereKey($validated['plan_id'])->firstOrFail(), $validated['method'] ?? null);
        $pack = $result['user_pack']->load(['plan', 'version', 'account']);

        return response()->json([
            'data' => [
                'user_pack' => new UserPackResource($pack),
                'payment' => $result['attempt'] === null ? null : new PaymentAttemptResource($result['attempt']),
            ],
        ], $result['created'] ? 201 : 200);
    }

    /**
     * Quota movements of the member's packs, most recent first.
     *
     * @return CursorPaginator<int, QuotaLedgerEntry>
     */
    public function ledger(Request $request): CursorPaginator
    {
        $validated = $request->validate([
            'user_pack_id' => ['nullable', 'string', 'ulid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $owned = UserPack::query()->where('user_id', $this->user($request)->id)->select('id');

        return QuotaLedgerEntry::query()
            ->whereIn('user_pack_id', $owned)
            ->when($validated['user_pack_id'] ?? null, fn ($query, string $id) => $query->where('user_pack_id', $id))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($validated['per_page'] ?? 50, ['id', 'user_pack_id', 'type', 'available_delta', 'reserved_delta', 'consumed_delta', 'available_after', 'reserved_after', 'consumed_after', 'reason', 'created_at']);
    }

    /**
     * Top-ups of one of the member's Personnel packs, most recent first.
     */
    public function topups(Request $request, string $userPack): AnonymousResourceCollection
    {
        $pack = $this->owned($request, $userPack);

        return PackTopupResource::collection($pack->topups()->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get());
    }

    /**
     * Top up a Personnel pack ("rechargement Perso"): the member chooses the amount (at least the pack's minimum)
     * and the method. The YEM bought are credited once the payment is confirmed by the provider or validated by the
     * CECT. Returns the top-up and the payment to complete (201), or the one already awaiting payment (200).
     */
    public function topup(Request $request, string $userPack, RequestPackTopup $requestTopup): JsonResponse
    {
        $validated = $request->validate([
            'amount_xof' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'method' => ['nullable', 'string', 'max:30'],
        ]);

        $result = $requestTopup->handle($this->user($request), $this->owned($request, $userPack), (int) $validated['amount_xof'], $validated['method'] ?? null);

        return response()->json([
            'data' => [
                'topup' => new PackTopupResource($result['topup']),
                'payment' => new PaymentAttemptResource($result['attempt']),
            ],
        ], $result['created'] ? 201 : 200);
    }

    private function owned(Request $request, string $id): UserPack
    {
        return UserPack::query()->where('user_id', $this->user($request)->id)->findOrFail($id);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
