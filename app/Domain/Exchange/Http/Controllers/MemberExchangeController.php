<?php

namespace App\Domain\Exchange\Http\Controllers;

use App\Domain\Exchange\Actions\SellerExchangeService;
use App\Domain\Exchange\Http\Resources\ExchangeRequestResource;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A member sells YEM to the CECT with a Pack Vendeur.
 */
class MemberExchangeController
{
    public function __construct(private readonly SellerExchangeService $exchanges) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $exchanges = ExchangeRequest::query()
            ->where('user_id', $this->member($request)->id)
            ->with('payoutBatch')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return ExchangeRequestResource::collection($exchanges);
    }

    public function show(Request $request, string $exchange): ExchangeRequestResource
    {
        return new ExchangeRequestResource($this->owned($request, $exchange)->load(['payoutBatch', 'validationEvents']));
    }

    /**
     * Ask to sell YEM to the CECT.
     *
     * Reserves the quantity on the pack's quota and returns the CECT Pernum to send the YEM to. Nothing is payable
     * until a Validator has confirmed the arrival of the YEM.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_pack_id' => ['required', 'string', 'ulid'],
            'quantity' => ['required', 'regex:/^\d{1,22}(\.\d{1,6})?$/'],
        ]);

        $member = $this->member($request);
        $pack = UserPack::query()->with('version')->where('user_id', $member->id)->whereKey($validated['user_pack_id'])->firstOrFail();

        $exchange = $this->exchanges->create($member, $pack, (string) $validated['quantity']);

        return (new ExchangeRequestResource($exchange))->response()->setStatusCode(201);
    }

    /**
     * Tell the CECT the YEM were sent. This does not make anything payable.
     */
    public function declareSent(Request $request, string $exchange): ExchangeRequestResource
    {
        $validated = $request->validate([
            'transfer_reference' => ['nullable', 'string', 'max:120'],
        ]);

        return new ExchangeRequestResource($this->exchanges->declareSent($this->member($request), $this->owned($request, $exchange), $validated['transfer_reference'] ?? null));
    }

    /**
     * Cancel a request before declaring the transfer: the reserved quota is given back.
     */
    public function cancel(Request $request, string $exchange): ExchangeRequestResource
    {
        return new ExchangeRequestResource($this->exchanges->cancel($this->member($request), $this->owned($request, $exchange)));
    }

    private function owned(Request $request, string $id): ExchangeRequest
    {
        return ExchangeRequest::query()->where('user_id', $this->member($request)->id)->whereKey($id)->firstOrFail();
    }

    private function member(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
