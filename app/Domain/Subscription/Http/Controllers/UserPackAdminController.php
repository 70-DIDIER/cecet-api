<?php

namespace App\Domain\Subscription\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Quota\Models\QuotaLedgerEntry;
use App\Domain\Quota\QuotaLedger;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Domain\Subscription\Http\Resources\UserPackResource;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Back-office view of the packs held by members, and audited quota adjustments.
 */
class UserPackAdminController
{
    /**
     * Packs held by members, most recent first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(UserPackStatus::class)],
            'family' => ['nullable', Rule::enum(PackFamily::class)],
            'user_id' => ['nullable', 'string', 'ulid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $packs = UserPack::query()
            ->with(['plan', 'version', 'account'])
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['family'] ?? null, fn ($query, string $family) => $query->where('family', $family))
            ->when($validated['user_id'] ?? null, fn ($query, string $userId) => $query->where('user_id', $userId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 50);

        return UserPackResource::collection($packs);
    }

    /**
     * One pack with its periods and the last quota movements.
     */
    public function show(UserPack $userPack): JsonResponse
    {
        $userPack->load(['plan', 'version', 'account', 'periods']);

        return response()->json(['data' => [
            ...(new UserPackResource($userPack))->resolve(),
            'owner_id' => $userPack->user_id,
            'ledger' => QuotaLedgerEntry::query()
                ->where('user_pack_id', $userPack->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'type', 'available_delta', 'reserved_delta', 'consumed_delta', 'available_after', 'reason', 'actor_id', 'created_at']),
        ]]);
    }

    /**
     * Correct a pack's available quota (positive or negative) with a mandatory reason.
     *
     * Reserved to the Superadmin by default, requires a recent step-up confirmation and an Idempotency-Key,
     * and leaves a compensating ledger entry plus an audit event: nothing is ever rewritten.
     */
    public function adjust(Request $request, UserPack $userPack, QuotaLedger $ledger, AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'regex:/^-?\d{1,22}(\.\d{1,6})?$/'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $entry = $ledger->adjust($userPack, $validated['quantity'], 'adjust:'.$request->header('Idempotency-Key'), $actor, $validated['reason']);

        $auditLogger->record(
            'quota.adjusted',
            $userPack,
            after: ['quantity' => $validated['quantity'], 'available_after' => $entry->available_after, 'ledger_entry_id' => $entry->id],
            reason: $validated['reason'],
            actor: $actor,
        );

        return response()->json(['data' => [
            'ledger_entry_id' => $entry->id,
            'available_after' => $entry->available_after,
            'reserved_after' => $entry->reserved_after,
            'consumed_after' => $entry->consumed_after,
        ]], 201);
    }
}
