<?php

namespace App\Domain\Payment\Http\Controllers;

use App\Domain\Payment\Actions\ManualPaymentDesk;
use App\Domain\Payment\Http\Resources\PaymentAttemptResource;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\ValidationQueue;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The single "Validations" queue of the back-office, as on the former site: memberships, packs and top-ups paid
 * outside the provider, and YEM declared as sent to the CECT. Each staff member only sees what their permissions
 * allow them to decide.
 */
class ValidationQueueController
{
    public function __construct(
        private readonly ManualPaymentDesk $desk,
        private readonly ValidationQueue $queue,
    ) {}

    /**
     * Items awaiting a decision ("pending"), or the recent decisions ("history").
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['nullable', 'in:pending,history']]);
        $history = ($validated['status'] ?? 'pending') === 'history';
        $user = $this->staff($request);

        $canPayments = $user->can('payments.validate_manual');
        $canYem = $user->can('yem_validations.view');
        abort_unless($canPayments || $canYem, 403);

        $items = $this->queue->items($canPayments, $canYem, $history);

        return response()->json([
            'data' => $items,
            'meta' => ['pending_count' => $history ? null : $items->count()],
        ]);
    }

    /**
     * Validate a payment made outside the provider once the money has been received: activates what was paid for.
     */
    public function approve(Request $request, PaymentAttempt $attempt): PaymentAttemptResource
    {
        $validated = $request->validate([
            'received_amount' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:120'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        return new PaymentAttemptResource($this->desk->approve($this->staff($request), $attempt, (int) $validated['received_amount'], $validated['reference'], $validated['comment'] ?? null));
    }

    /**
     * Reject a declared payment that cannot be found.
     */
    public function reject(Request $request, PaymentAttempt $attempt): PaymentAttemptResource
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return new PaymentAttemptResource($this->desk->reject($this->staff($request), $attempt, $validated['reason']));
    }

    private function staff(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
