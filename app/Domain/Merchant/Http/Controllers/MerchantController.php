<?php

namespace App\Domain\Merchant\Http\Controllers;

use App\Domain\Exchange\Http\Resources\ExchangeRequestResource;
use App\Domain\Merchant\Actions\MerchantPaymentService;
use App\Domain\Merchant\Actions\MerchantQrService;
use App\Domain\Merchant\Actions\MerchantRedemptionService;
use App\Domain\Merchant\Http\Resources\MerchantPaymentResource;
use App\Domain\Merchant\Models\MerchantAccount;
use App\Domain\Merchant\Models\MerchantLedgerEntry;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Domain\Merchant\Models\MerchantQrCode;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use App\Support\YemQuantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The merchant's side: its QR codes, the payments it receives, and its balance eligible for redemption.
 */
class MerchantController
{
    public function __construct(
        private readonly MerchantQrService $qrService,
        private readonly MerchantPaymentService $payments,
    ) {}

    public function qrCodes(Request $request): JsonResponse
    {
        $codes = MerchantQrCode::query()
            ->where('user_id', $this->user($request)->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MerchantQrCode $qr): array => $this->qrPayload($qr));

        return response()->json(['data' => $codes]);
    }

    /**
     * Issue a QR code for an active Pack Marchand, optionally for a point of sale.
     */
    public function issueQr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_pack_id' => ['required', 'string', 'ulid'],
            'outlet_label' => ['nullable', 'string', 'max:120'],
        ]);

        $merchant = $this->user($request);
        $pack = UserPack::query()->where('user_id', $merchant->id)->whereKey($validated['user_pack_id'])->firstOrFail();

        return response()->json(['data' => $this->qrPayload($this->qrService->issue($merchant, $pack, $validated['outlet_label'] ?? null))], 201);
    }

    /**
     * Replace a QR code by a new one (leak, change of Business Pernum): the old one stops working immediately.
     */
    public function rotateQr(Request $request, string $qr): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['data' => $this->qrPayload($this->qrService->rotate($this->user($request), $this->ownedQr($request, $qr), $validated['reason']))], 201);
    }

    public function revokeQr(Request $request, string $qr): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['data' => $this->qrPayload($this->qrService->revoke($this->user($request), $this->ownedQr($request, $qr), $validated['reason']))]);
    }

    /**
     * Payments addressed to the merchant, most recent first.
     */
    public function payments(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['status' => ['nullable', Rule::in(['reserved', 'declared_sent', 'confirmed', 'canceled', 'expired', 'disputed', 'rejected'])]]);

        $payments = MerchantPayment::query()
            ->where('merchant_id', $this->user($request)->id)
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->with('payer.organization')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return MerchantPaymentResource::collection($payments);
    }

    /**
     * The merchant confirms the YEM arrived on its Pernum: the payer's quota is consumed and the merchant's
     * redeemable balance credited.
     */
    public function confirm(Request $request, string $payment): MerchantPaymentResource
    {
        return new MerchantPaymentResource($this->payments->confirmByMerchant($this->user($request), $this->ownedPayment($request, $payment))->load('payer.organization'));
    }

    /**
     * The merchant reports a declared payment as not received: a Validator of the CECT arbitrates.
     */
    public function reportNotReceived(Request $request, string $payment): MerchantPaymentResource
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return new MerchantPaymentResource($this->payments->reportNotReceived($this->user($request), $this->ownedPayment($request, $payment), $validated['reason'])->load('payer.organization'));
    }

    /**
     * Ask the CECT to redeem collected YEM: reserves the quantity on the eligible balance (and on the Pack Marchand's
     * periodic quota when configured) and returns the CECT Pernum to send the YEM to. The request then follows the
     * member exchange endpoints (declare-sent, cancel) and the Validator's confirmation makes the counter-value payable.
     */
    public function redeem(Request $request, MerchantRedemptionService $redemptions): JsonResponse
    {
        $validated = $request->validate([
            'user_pack_id' => ['required', 'string', 'ulid'],
            'quantity' => ['required', 'regex:/^\d{1,22}(\.\d{1,6})?$/'],
        ]);

        $merchant = $this->user($request);
        $pack = UserPack::query()->with('version')->where('user_id', $merchant->id)->whereKey($validated['user_pack_id'])->firstOrFail();

        return (new ExchangeRequestResource($redemptions->create($merchant, $pack, (string) $validated['quantity'])))->response()->setStatusCode(201);
    }

    /**
     * Balance of each merchant pack (eligible for redemption, reserved, redeemed) and the latest movements.
     */
    public function balance(Request $request): JsonResponse
    {
        $packIds = UserPack::query()->where('user_id', $this->user($request)->id)->where('family', 'MARCHAND')->pluck('id');
        $accounts = MerchantAccount::query()->whereIn('user_pack_id', $packIds)->get()->keyBy('user_pack_id');

        return response()->json(['data' => [
            'packs' => $packIds->map(fn (string $id): array => [
                'user_pack_id' => $id,
                'eligible' => YemQuantity::of($accounts->get($id)->eligible ?? '0'),
                'reserved' => YemQuantity::of($accounts->get($id)->reserved ?? '0'),
                'redeemed' => YemQuantity::of($accounts->get($id)->redeemed ?? '0'),
            ])->values()->all(),
            'recent_movements' => MerchantLedgerEntry::query()
                ->whereIn('user_pack_id', $packIds)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'user_pack_id', 'type', 'eligible_delta', 'reserved_delta', 'redeemed_delta', 'eligible_after', 'reason', 'created_at']),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function qrPayload(MerchantQrCode $qr): array
    {
        return [
            'id' => $qr->id,
            'user_pack_id' => $qr->user_pack_id,
            'outlet_label' => $qr->outlet_label,
            'version' => $qr->version,
            'status' => $qr->status,
            'code' => $qr->isActive() ? $qr->token : null,
            'payload' => $qr->isActive() ? $qr->payload() : null,
            'revoked_at' => $qr->revoked_at?->toIso8601ZuluString(),
            'created_at' => $qr->created_at->toIso8601ZuluString(),
        ];
    }

    private function ownedQr(Request $request, string $id): MerchantQrCode
    {
        return MerchantQrCode::query()->where('user_id', $this->user($request)->id)->findOrFail($id);
    }

    private function ownedPayment(Request $request, string $id): MerchantPayment
    {
        return MerchantPayment::query()->where('merchant_id', $this->user($request)->id)->findOrFail($id);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
