<?php

namespace App\Domain\Merchant\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Merchant\Http\Resources\MerchantPaymentResource;
use App\Domain\Merchant\MerchantLedger;
use App\Domain\Merchant\Models\MerchantAccount;
use App\Domain\Merchant\Models\MerchantLedgerEntry;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use App\Support\YemQuantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Back-office view of merchant payments and merchant balances, with audited corrections.
 */
class MerchantAdminController
{
    public function payments(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['reserved', 'declared_sent', 'confirmed', 'canceled', 'expired', 'disputed', 'rejected'])],
            'merchant_id' => ['nullable', 'string', 'ulid'],
            'payer_id' => ['nullable', 'string', 'ulid'],
            'reference' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $payments = MerchantPayment::query()
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['merchant_id'] ?? null, fn ($query, string $id) => $query->where('merchant_id', $id))
            ->when($validated['payer_id'] ?? null, fn ($query, string $id) => $query->where('payer_id', $id))
            ->when($validated['reference'] ?? null, fn ($query, string $reference) => $query->where('reference', mb_strtoupper($reference)))
            ->with(['payer.organization', 'merchant.organization'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        return MerchantPaymentResource::collection($payments);
    }

    /**
     * A merchant pack's balance and its full ledger, most recent first.
     */
    public function account(UserPack $userPack): JsonResponse
    {
        abort_unless($userPack->family === PackFamily::Marchand, 404);
        $account = MerchantAccount::query()->find($userPack->id);

        return response()->json(['data' => [
            'user_pack_id' => $userPack->id,
            'merchant_id' => $userPack->user_id,
            'eligible' => YemQuantity::of($account->eligible ?? '0'),
            'reserved' => YemQuantity::of($account->reserved ?? '0'),
            'redeemed' => YemQuantity::of($account->redeemed ?? '0'),
            'entries' => MerchantLedgerEntry::query()->where('user_pack_id', $userPack->id)->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get(),
        ]]);
    }

    /**
     * Audited correction of a merchant's eligible balance (compensating entry, never an edit).
     */
    public function adjust(Request $request, UserPack $userPack, MerchantLedger $ledger, AuditLogger $auditLogger): JsonResponse
    {
        abort_unless($userPack->family === PackFamily::Marchand, 404);

        $validated = $request->validate([
            'quantity' => ['required', 'regex:/^-?\d{1,22}(\.\d{1,6})?$/'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $entry = $ledger->adjust($userPack, $validated['quantity'], 'merchant_adjust:'.$request->header('Idempotency-Key'), $actor, $validated['reason']);

        $auditLogger->record(
            'merchant_balance.adjusted',
            $userPack,
            after: ['quantity' => $validated['quantity'], 'eligible_after' => $entry->eligible_after, 'ledger_entry_id' => $entry->id],
            reason: $validated['reason'],
            actor: $actor,
        );

        return response()->json(['data' => [
            'ledger_entry_id' => $entry->id,
            'eligible_after' => $entry->eligible_after,
            'reserved_after' => $entry->reserved_after,
            'redeemed_after' => $entry->redeemed_after,
        ]], 201);
    }
}
