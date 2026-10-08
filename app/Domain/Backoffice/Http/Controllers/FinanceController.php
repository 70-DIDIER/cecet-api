<?php

namespace App\Domain\Backoffice\Http\Controllers;

use App\Domain\Payout\Actions\ManualSettlementService;
use App\Domain\Payout\Actions\PayoutOperations;
use App\Domain\Payout\Enums\ManualSettlementStatus;
use App\Domain\Payout\Enums\PayoutBatchStatus;
use App\Domain\Payout\Enums\PayoutRoute;
use App\Domain\Payout\Models\ManualSettlement;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Exceptions\ApiException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Accounting: the counter-values owed to members and merchants, transfers, retries and manual settlements.
 * Accounting never changes a YEM validation: it only sees it.
 */
class FinanceController
{
    public function __construct(
        private readonly PayoutOperations $operations,
        private readonly ManualSettlementService $settlements,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(PayoutBatchStatus::class)],
            'route' => ['nullable', Rule::enum(PayoutRoute::class)],
            'search' => ['nullable', 'string', 'max:60'],
        ]);

        $batches = PayoutBatch::query()
            ->with(['beneficiary.organization', 'payable'])
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['route'] ?? null, fn ($query, string $route) => $query->where('route', $route))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where('reference', 'like', '%'.addcslashes(mb_strtoupper($search), '%_\\').'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('backoffice.finance.payouts', [
            'batches' => $batches,
            'filters' => $filters,
            'statuses' => PayoutBatchStatus::cases(),
            'owed' => PayoutBatch::query()->where('status', '<>', PayoutBatchStatus::Paid)->sum('remaining_amount'),
            'needAction' => PayoutBatch::query()->whereIn('status', [PayoutBatchStatus::Failed, PayoutBatchStatus::ManualRequired])->count(),
            'pendingSettlements' => ManualSettlement::query()->where('status', ManualSettlementStatus::PendingApproval)->count(),
        ]);
    }

    public function show(PayoutBatch $payout): View
    {
        $payout->load(['beneficiary.organization', 'payable', 'items', 'manualSettlements']);

        return view('backoffice.finance.payout', [
            'payout' => $payout,
            'manualMethods' => $this->manualMethods(),
            'autoEnabled' => $this->settings->boolean('payout.auto_enabled'),
        ]);
    }

    public function dispatch(Request $request, PayoutBatch $payout): RedirectResponse
    {
        return $this->run($payout, fn () => $this->operations->dispatch($this->user($request), $payout), 'Les virements sont partis chez le prestataire.');
    }

    public function retry(Request $request, PayoutBatch $payout): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->run($payout, fn () => $this->operations->retry($this->user($request), $payout, $validated['reason']), 'Les virements en échec ont été relancés (ceux déjà versés ne sont jamais renvoyés).');
    }

    public function refresh(PayoutBatch $payout): RedirectResponse
    {
        return $this->run($payout, fn () => $this->operations->refresh($payout), 'Statut mis à jour auprès du prestataire.');
    }

    public function recordSettlement(Request $request, PayoutBatch $payout): RedirectResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in(array_keys($this->manualMethods()))],
            'amount' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:120'],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->run(
            $payout,
            fn () => $this->settlements->record(
                $this->user($request),
                $payout,
                $validated['method'],
                $validated['reference'],
                (int) $validated['amount'],
                CarbonImmutable::parse($validated['paid_at']),
                $validated['note'] ?? null,
            ),
            'Règlement manuel enregistré. Au-dessus du seuil, une autre personne doit l\'approuver.',
        );
    }

    public function settlements(): View
    {
        return view('backoffice.finance.settlements', [
            'pending' => ManualSettlement::query()->with(['batch.beneficiary.organization', 'recorder'])->where('status', ManualSettlementStatus::PendingApproval)->orderBy('created_at')->get(),
        ]);
    }

    public function approveSettlement(Request $request, ManualSettlement $settlement): RedirectResponse
    {
        return $this->after(fn () => $this->settlements->approve($this->user($request), $settlement), 'Règlement approuvé : le versement est mis à jour.');
    }

    public function rejectSettlement(Request $request, ManualSettlement $settlement): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->after(fn () => $this->settlements->reject($this->user($request), $settlement, $validated['reason']), 'Règlement rejeté.');
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function run(PayoutBatch $payout, callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (ApiException $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return redirect()->route('backoffice.finance.payout', $payout)->with('status', $success);
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function after(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (ApiException $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return redirect()->route('backoffice.finance.settlements')->with('status', $success);
    }

    /**
     * Methods a manual settlement can use: the enabled payment methods the cooperative handles by hand.
     *
     * @return array<string, string> code => label
     */
    private function manualMethods(): array
    {
        return collect($this->settings->array('payment.methods'))
            ->filter(fn (mixed $method): bool => is_array($method) && ($method['enabled'] ?? false) === true && isset($method['code'], $method['label']))
            ->mapWithKeys(fn (array $method): array => [(string) $method['code'] => (string) $method['label']])
            ->all();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
