<?php

namespace App\Domain\Audit\Http\Controllers;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\SecurityEvent;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

/**
 * Read-only access to the audit and security journals, most recent first, with cursor pagination.
 */
class AuditEventController
{
    /**
     * Business audit journal.
     *
     * @return CursorPaginator<int, AuditEvent>
     */
    public function index(Request $request): CursorPaginator
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'actor_id' => ['nullable', 'string', 'ulid'],
            'subject_type' => ['nullable', 'string', 'max:120'],
            'subject_id' => ['nullable', 'string', 'max:64'],
            'correlation_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return AuditEvent::query()
            ->when($validated['action'] ?? null, fn ($query, string $action) => $query->where('action', $action))
            ->when($validated['actor_id'] ?? null, fn ($query, string $actorId) => $query->where('actor_id', $actorId))
            ->when($validated['subject_type'] ?? null, fn ($query, string $type) => $query->where('subject_type', $type))
            ->when($validated['subject_id'] ?? null, fn ($query, string $id) => $query->where('subject_id', $id))
            ->when($validated['correlation_id'] ?? null, fn ($query, string $id) => $query->where('correlation_id', $id))
            ->when($validated['from'] ?? null, fn ($query, string $from) => $query->where('occurred_at', '>=', $from))
            ->when($validated['to'] ?? null, fn ($query, string $to) => $query->where('occurred_at', '<=', $to))
            ->orderByDesc('position')
            ->cursorPaginate($validated['per_page'] ?? 50);
    }

    /**
     * Security journal (authentication, OTP, privileges, sessions).
     *
     * @return CursorPaginator<int, SecurityEvent>
     */
    public function security(Request $request): CursorPaginator
    {
        $validated = $request->validate([
            'event_type' => ['nullable', 'string', 'max:80'],
            'user_id' => ['nullable', 'string', 'ulid'],
            'severity' => ['nullable', 'in:info,warning,critical'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return SecurityEvent::query()
            ->when($validated['event_type'] ?? null, fn ($query, string $type) => $query->where('event_type', $type))
            ->when($validated['user_id'] ?? null, fn ($query, string $userId) => $query->where('user_id', $userId))
            ->when($validated['severity'] ?? null, fn ($query, string $severity) => $query->where('severity', $severity))
            ->when($validated['from'] ?? null, fn ($query, string $from) => $query->where('occurred_at', '>=', $from))
            ->when($validated['to'] ?? null, fn ($query, string $to) => $query->where('occurred_at', '<=', $to))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate($validated['per_page'] ?? 50);
    }
}
