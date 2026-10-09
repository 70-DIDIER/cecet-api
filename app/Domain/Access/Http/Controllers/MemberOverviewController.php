<?php

namespace App\Domain\Access\Http\Controllers;

use App\Domain\Access\Exceptions\AccessException;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Exchange\Http\Resources\ExchangeRequestResource;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Http\Resources\UserResource;
use App\Domain\Membership\Http\Resources\MembershipResource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Merchant\Http\Resources\MerchantPaymentResource;
use App\Domain\Merchant\Models\MerchantAccount;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Domain\Payment\Http\Resources\PaymentAttemptResource;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Subscription\Http\Resources\UserPackResource;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * The full file of one member in a single call: profile, membership, packs, and the latest payments, exchanges,
 * merchant payments and audit events.
 */
class MemberOverviewController
{
    private const LATEST = 10;

    /**
     * Profile, membership and packs of a member, with their ten latest operations of each kind.
     */
    public function __invoke(User $user): JsonResponse
    {
        if ($user->kind !== UserKind::Member) {
            throw AccessException::wrongAccountKind();
        }

        $user->load(['organization', 'pernumIdentities']);

        $packs = UserPack::query()
            ->where('user_id', $user->id)
            ->with(['plan', 'version', 'account'])
            ->orderByDesc('created_at')
            ->get();
        $merchantAccounts = MerchantAccount::query()->whereIn('user_pack_id', $packs->pluck('id'))->get()->keyBy('user_pack_id');
        $membership = Membership::query()->where('user_id', $user->id)->first();
        $intentIds = PaymentIntent::query()->where('user_id', $user->id)->select('id');

        return response()->json(['data' => [
            'member' => new UserResource($user),
            'membership' => $membership === null ? null : new MembershipResource($membership),
            'packs' => $packs->map(function (UserPack $pack) use ($merchantAccounts): array {
                $account = $merchantAccounts->get($pack->id);

                return [
                    ...(new UserPackResource($pack))->resolve(),
                    'merchant_account' => $account === null ? null : [
                        'eligible' => $account->eligible,
                        'reserved' => $account->reserved,
                        'redeemed' => $account->redeemed,
                    ],
                ];
            })->all(),
            'payments' => PaymentAttemptResource::collection(
                PaymentAttempt::query()->whereIn('payment_intent_id', $intentIds)->orderByDesc('created_at')->limit(self::LATEST)->get(),
            )->resolve(),
            'exchanges' => ExchangeRequestResource::collection(
                ExchangeRequest::query()->where('user_id', $user->id)->orderByDesc('created_at')->limit(self::LATEST)->get(),
            )->resolve(),
            'merchant_payments' => MerchantPaymentResource::collection(
                MerchantPayment::query()
                    ->where(fn ($query) => $query->where('payer_id', $user->id)->orWhere('merchant_id', $user->id))
                    ->with(['payer.organization', 'merchant.organization'])
                    ->orderByDesc('created_at')
                    ->limit(self::LATEST)
                    ->get(),
            )->resolve(),
            'audit_events' => AuditEvent::query()
                ->where('subject_type', $user->getMorphClass())
                ->where('subject_id', $user->id)
                ->orderByDesc('position')
                ->limit(self::LATEST)
                ->get()
                ->map(fn (AuditEvent $event): array => [
                    'id' => $event->id,
                    'action' => $event->action,
                    'actor_id' => $event->actor_id,
                    'result' => $event->result->value,
                    'reason' => $event->reason,
                    'occurred_at' => $event->occurred_at->toIso8601ZuluString(),
                ])->all(),
        ]]);
    }
}
