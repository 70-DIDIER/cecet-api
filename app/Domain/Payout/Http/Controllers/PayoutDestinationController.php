<?php

namespace App\Domain\Payout\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Identity\Http\Requests\RegisterMemberRequest;
use App\Domain\Payout\Models\PayoutDestination;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Where the member receives counter-values. Changing it requires a recent step-up confirmation, is audited,
 * and never affects operations already created (they keep a snapshot).
 */
class PayoutDestinationController
{
    public function show(Request $request): JsonResponse
    {
        $destination = PayoutDestination::query()->where('user_id', $this->member($request)->id)->first();

        return response()->json(['data' => $destination?->snapshot()]);
    }

    public function update(Request $request, SettingsService $settings, AuditLogger $auditLogger, SecurityEventLogger $securityLogger): JsonResponse
    {
        $methods = collect($settings->array('payment.methods'))
            ->filter(fn (mixed $method): bool => is_array($method) && ($method['enabled'] ?? false) === true)
            ->keyBy('code');

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in($methods->keys()->all())],
            'phone' => ['nullable', 'string', 'regex:'.RegisterMemberRequest::PHONE_FORMAT],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_holder' => ['nullable', 'string', 'max:160'],
            'account_number' => ['nullable', 'string', 'max:60'],
        ]);

        $channel = (string) data_get($methods->get($validated['method']), 'channel', 'manual');

        if ($channel === 'mobile_money' && empty($validated['phone'])) {
            throw ValidationException::withMessages(['phone' => 'Un numéro Mobile Money est obligatoire pour ce moyen de paiement.']);
        }

        $member = $this->member($request);

        $destination = DB::transaction(function () use ($member, $validated, $channel, $auditLogger): PayoutDestination {
            $existing = PayoutDestination::query()->where('user_id', $member->id)->lockForUpdate()->first();
            $before = $existing?->snapshot();

            $destination = PayoutDestination::query()->updateOrCreate(['user_id' => $member->id], [
                'method' => $validated['method'],
                'channel' => $channel,
                'phone' => $validated['phone'] ?? null,
                'bank_name' => $validated['bank_name'] ?? null,
                'account_holder' => $validated['account_holder'] ?? null,
                'account_number' => $validated['account_number'] ?? null,
            ]);

            $auditLogger->record('payout_destinations.updated', $destination, before: $before, after: $destination->snapshot(), actor: $member);

            return $destination;
        });

        $securityLogger->record('payout_destination.changed', SecuritySeverity::Warning, $member->id, metadata: ['method' => $destination->method]);

        return response()->json(['data' => $destination->snapshot()]);
    }

    private function member(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isMember(), 403);

        return $user;
    }
}
