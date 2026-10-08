<?php

namespace App\Domain\Exchange\Http\Controllers;

use App\Domain\Exchange\Actions\ValidationDesk;
use App\Domain\Exchange\Enums\ExchangeStatus;
use App\Domain\Exchange\Http\Resources\ExchangeRequestResource;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Validator dashboard: YEM declared as sent to the CECT Pernum, to be checked against the YEM ecosystem.
 */
class ValidationController
{
    public function __construct(private readonly ValidationDesk $desk) {}

    /**
     * Queue of requests to check. Defaults to declared transfers and disputes, oldest first.
     */
    public function pending(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(ExchangeStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $statuses = $validated['status'] ?? [ExchangeStatus::DeclaredSent->value, ExchangeStatus::Disputed->value];

        $exchanges = ExchangeRequest::query()
            ->whereIn('status', $statuses)
            ->with('user:id,first_name,last_name,member_number')
            ->orderBy('declared_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 50);

        return ExchangeRequestResource::collection($exchanges);
    }

    public function show(ExchangeRequest $exchange): ExchangeRequestResource
    {
        return new ExchangeRequestResource($exchange->load(['payoutBatch', 'validationEvents']));
    }

    /**
     * Confirm the YEM actually received on the CECT Pernum (possibly less than requested).
     */
    public function confirm(Request $request, ExchangeRequest $exchange): ExchangeRequestResource
    {
        $validated = $request->validate([
            'quantity_received' => ['required', 'regex:/^\d{1,22}(\.\d{1,6})?$/'],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $exchange = $this->desk->confirm($this->validator($request), $exchange, (string) $validated['quantity_received'], $validated['external_reference'] ?? null, $validated['comment'] ?? null);

        return new ExchangeRequestResource($exchange->load(['payoutBatch', 'validationEvents']));
    }

    /**
     * Reject: transfer not found or inconsistent. The reserved quota is given back.
     */
    public function reject(Request $request, ExchangeRequest $exchange): ExchangeRequestResource
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return new ExchangeRequestResource($this->desk->reject($this->validator($request), $exchange, $validated['reason'])->load('validationEvents'));
    }

    /**
     * Place in dispute: the quota stays reserved until the Validator confirms or rejects.
     */
    public function dispute(Request $request, ExchangeRequest $exchange): ExchangeRequestResource
    {
        $validated = $request->validate(['comment' => ['required', 'string', 'min:5', 'max:2000']]);

        return new ExchangeRequestResource($this->desk->dispute($this->validator($request), $exchange, $validated['comment'])->load('validationEvents'));
    }

    private function validator(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
