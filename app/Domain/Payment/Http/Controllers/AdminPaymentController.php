<?php

namespace App\Domain\Payment\Http\Controllers;

use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\WebhookEventStatus;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\ProviderWebhookEvent;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read-only back-office view of payment attempts and received webhooks.
 */
class AdminPaymentController
{
    /**
     * Payment attempts, most recent first. Filter on those that need a manual review.
     *
     * @return CursorPaginator<int, PaymentAttempt>
     */
    public function attempts(Request $request): CursorPaginator
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(PaymentAttemptStatus::class)],
            'requires_review' => ['nullable', 'boolean'],
            'user_id' => ['nullable', 'string', 'ulid'],
            'provider' => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return PaymentAttempt::query()
            ->with('intent:id,user_id,purpose,payable_type,payable_id')
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when(isset($validated['requires_review']), fn ($query) => $query->where('requires_review', $request->boolean('requires_review')))
            ->when($validated['user_id'] ?? null, fn ($query, string $userId) => $query->whereHas('intent', fn ($intent) => $intent->where('user_id', $userId)))
            ->when($validated['provider'] ?? null, fn ($query, string $provider) => $query->where('provider', $provider))
            ->orderByDesc('id')
            ->cursorPaginate($validated['per_page'] ?? 50);
    }

    /**
     * Authenticated webhooks received from providers.
     *
     * @return CursorPaginator<int, ProviderWebhookEvent>
     */
    public function webhookEvents(Request $request): CursorPaginator
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(WebhookEventStatus::class)],
            'provider' => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ProviderWebhookEvent::query()
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['provider'] ?? null, fn ($query, string $provider) => $query->where('provider', $provider))
            ->orderByDesc('id')
            ->cursorPaginate($validated['per_page'] ?? 50);
    }
}
