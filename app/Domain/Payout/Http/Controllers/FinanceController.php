<?php

namespace App\Domain\Payout\Http\Controllers;

use App\Domain\Payout\Actions\ManualSettlementService;
use App\Domain\Payout\Actions\PayoutOperations;
use App\Domain\Payout\Enums\ManualSettlementStatus;
use App\Domain\Payout\Enums\PayoutBatchStatus;
use App\Domain\Payout\Enums\PayoutRoute;
use App\Domain\Payout\Http\Resources\PayoutBatchResource;
use App\Domain\Payout\Models\ManualSettlement;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Accounting dashboard: counter-values owed, transfers, retries, manual settlements and their approvals.
 * Accounting never changes a YEM validation: it only sees it.
 */
class FinanceController
{
    public function __construct(
        private readonly PayoutOperations $operations,
        private readonly ManualSettlementService $settlements,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(PayoutBatchStatus::class)],
            'route' => ['nullable', Rule::enum(PayoutRoute::class)],
            'method' => ['nullable', 'string', 'max:30'],
            'beneficiary_id' => ['nullable', 'string', 'ulid'],
            'search' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $batches = PayoutBatch::query()
            ->with(['beneficiary', 'payable'])
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['route'] ?? null, fn ($query, string $route) => $query->where('route', $route))
            ->when($validated['method'] ?? null, fn ($query, string $method) => $query->where('method', $method))
            ->when($validated['beneficiary_id'] ?? null, fn ($query, string $id) => $query->where('beneficiary_id', $id))
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where('reference', 'like', '%'.addcslashes(mb_strtoupper($search), '%_\\').'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 50);

        return PayoutBatchResource::collection($batches)->additional(['summary' => [
            'owed_amount' => (int) PayoutBatch::query()->where('status', '<>', PayoutBatchStatus::Paid)->sum('remaining_amount'),
            'needing_action' => PayoutBatch::query()->whereIn('status', [PayoutBatchStatus::Failed, PayoutBatchStatus::ManualRequired])->count(),
            'pending_settlements' => ManualSettlement::query()->where('status', ManualSettlementStatus::PendingApproval)->count(),
        ]]);
    }

    public function show(PayoutBatch $payout): PayoutBatchResource
    {
        return new PayoutBatchResource($payout->load(['beneficiary', 'payable', 'items', 'manualSettlements']));
    }

    /**
     * Send the Mobile Money transfers of a payout (when automatic payouts are disabled).
     */
    public function dispatch(Request $request, PayoutBatch $payout): PayoutBatchResource
    {
        return $this->present($this->operations->dispatch($this->actor($request), $payout));
    }

    /**
     * Send again the transfers that failed. Transfers already paid are never sent again.
     */
    public function retry(Request $request, PayoutBatch $payout): PayoutBatchResource
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->present($this->operations->retry($this->actor($request), $payout, $validated['reason']));
    }

    /**
     * Ask the provider for the outcome of transfers still in progress.
     */
    public function refresh(PayoutBatch $payout): PayoutBatchResource
    {
        $this->operations->refresh($payout);

        return $this->present($payout->refresh());
    }

    /**
     * Record a bank transfer or a cash payment covering the remaining amount. At or above the configured threshold,
     * it must be approved by another person before it settles the payout.
     */
    public function recordSettlement(Request $request, PayoutBatch $payout, SettingsService $settings): JsonResponse
    {
        $methods = collect($settings->array('payment.methods'))
            ->filter(fn (mixed $method): bool => is_array($method) && ($method['enabled'] ?? false) === true)
            ->pluck('code')
            ->all();

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in($methods)],
            'amount' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:120'],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $settlement = $this->settlements->record(
            $this->actor($request),
            $payout,
            $validated['method'],
            $validated['reference'],
            (int) $validated['amount'],
            CarbonImmutable::parse($validated['paid_at']),
            $validated['note'] ?? null,
        );

        return response()->json(['data' => [
            'settlement_id' => $settlement->id,
            'status' => $settlement->status->value,
            'payout' => $this->present($payout->refresh()),
        ]], 201);
    }

    /**
     * Settlements waiting for a second person's approval.
     */
    public function pendingSettlements(): JsonResponse
    {
        $pending = ManualSettlement::query()
            ->with('batch')
            ->where('status', ManualSettlementStatus::PendingApproval)
            ->orderBy('created_at')
            ->get()
            ->map(fn (ManualSettlement $settlement): array => [
                'id' => $settlement->id,
                'payout_reference' => $settlement->batch->reference,
                'amount' => $settlement->amount,
                'method' => $settlement->method,
                'reference' => $settlement->reference,
                'recorded_by' => $settlement->recorded_by,
                'paid_at' => $settlement->paid_at->toIso8601ZuluString(),
            ]);

        return response()->json(['data' => $pending]);
    }

    public function approveSettlement(Request $request, ManualSettlement $settlement): JsonResponse
    {
        $settlement = $this->settlements->approve($this->actor($request), $settlement);

        return response()->json(['data' => ['settlement_id' => $settlement->id, 'status' => $settlement->status->value]]);
    }

    public function rejectSettlement(Request $request, ManualSettlement $settlement): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $settlement = $this->settlements->reject($this->actor($request), $settlement, $validated['reason']);

        return response()->json(['data' => ['settlement_id' => $settlement->id, 'status' => $settlement->status->value]]);
    }

    private function present(PayoutBatch $batch): PayoutBatchResource
    {
        return new PayoutBatchResource($batch->load(['beneficiary', 'payable', 'items', 'manualSettlements']));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
