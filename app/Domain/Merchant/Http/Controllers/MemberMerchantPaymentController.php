<?php

namespace App\Domain\Merchant\Http\Controllers;

use App\Domain\Merchant\Actions\MerchantPaymentService;
use App\Domain\Merchant\Actions\MerchantQrService;
use App\Domain\Merchant\Http\Resources\MerchantPaymentResource;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The payer's side: scan a merchant QR, reserve the YEM to pay, declare the transfer, follow the payment.
 */
class MemberMerchantPaymentController
{
    public function __construct(private readonly MerchantPaymentService $payments) {}

    /**
     * Who the scanned QR designates, as known by the server now (fallback: the merchant code typed by hand).
     * Nothing is reserved and no YEM moves.
     */
    public function resolve(string $token, MerchantQrService $qrService): JsonResponse
    {
        $resolved = $qrService->resolve($token);

        return response()->json(['data' => [
            'merchant' => [
                'id' => $resolved['merchant']->id,
                'name' => $resolved['merchant']->organization->legal_name ?? $resolved['merchant']->full_name,
                'member_number' => $resolved['merchant']->member_number,
            ],
            'outlet_label' => $resolved['qr']->outlet_label,
            'pernum' => $resolved['pernum'],
        ]]);
    }

    /**
     * Create the payment intent: reserves the quantity on the chosen Consommateur/Personnel pack. The member then
     * transfers the YEM to the merchant's Pernum outside the platform, and declares it.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'user_pack_id' => ['required', 'string', 'ulid'],
            'quantity' => ['required'],
        ]);

        $user = $this->user($request);
        $pack = UserPack::query()->where('user_id', $user->id)->whereKey($validated['user_pack_id'])->firstOrFail();
        $payment = $this->payments->create($user, $pack, $validated['token'], is_scalar($validated['quantity']) ? (string) $validated['quantity'] : '');

        return (new MerchantPaymentResource($payment->load('merchant.organization')))->response()->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $payments = MerchantPayment::query()
            ->where('payer_id', $this->user($request)->id)
            ->with('merchant.organization')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return MerchantPaymentResource::collection($payments);
    }

    public function show(Request $request, string $payment): MerchantPaymentResource
    {
        return new MerchantPaymentResource($this->owned($request, $payment)->load('merchant.organization'));
    }

    public function declareSent(Request $request, string $payment): MerchantPaymentResource
    {
        $validated = $request->validate(['reference' => ['nullable', 'string', 'max:120']]);

        return new MerchantPaymentResource($this->payments->declareSent($this->user($request), $this->owned($request, $payment), $validated['reference'] ?? null));
    }

    public function cancel(Request $request, string $payment): MerchantPaymentResource
    {
        return new MerchantPaymentResource($this->payments->cancel($this->user($request), $this->owned($request, $payment)));
    }

    private function owned(Request $request, string $id): MerchantPayment
    {
        return MerchantPayment::query()->where('payer_id', $this->user($request)->id)->findOrFail($id);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
