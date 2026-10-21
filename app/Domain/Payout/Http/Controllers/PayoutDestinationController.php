<?php

namespace App\Domain\Payout\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Payout\Models\PayoutDestination;
use App\Domain\Payout\PayoutCountries;
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

    public function update(Request $request, SettingsService $settings, PayoutCountries $countries, AuditLogger $auditLogger, SecurityEventLogger $securityLogger): JsonResponse
    {
        $methods = collect($settings->array('payment.methods'))
            ->filter(fn (mixed $method): bool => is_array($method) && ($method['enabled'] ?? false) === true)
            ->keyBy('code');

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in($methods->keys()->all())],
            'country' => ['required', 'string', 'size:2', 'alpha:ascii'],
            'dial_code' => ['nullable', 'string', 'max:6'],
            'phone' => ['nullable', 'string', 'max:30'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_holder' => ['nullable', 'string', 'max:160'],
            'account_number' => ['nullable', 'string', 'max:60'],
        ]);

        $methodConfig = $methods->get($validated['method']);
        $channel = (string) data_get($methodConfig, 'channel', 'manual');
        // Card payouts require a PSP-issued recipient token. Never collect
        // or persist raw card numbers as a generic payout destination.
        if ($channel === 'card') {
            throw ValidationException::withMessages([
                'method' => 'Le versement vers carte bancaire nécessite un prestataire de paiement compatible. Choisissez Mobile Money ou virement pour cette démonstration.',
            ]);
        }
        if ($validated['method'] === 'BANK_TRANSFER'
            && (empty($validated['bank_name']) || empty($validated['account_holder']) || empty($validated['account_number']))) {
            throw ValidationException::withMessages([
                'bank_name' => 'Banque, titulaire et numéro de compte sont obligatoires pour un virement.',
            ]);
        }
        $country = strtoupper($validated['country']);
        $allowedCountries = collect(data_get($methodConfig, 'countries', []))->map(fn (mixed $value): string => strtoupper((string) $value))->all();

        if ($allowedCountries !== [] && ! in_array($country, $allowedCountries, true)) {
            throw ValidationException::withMessages(['country' => 'Ce moyen de versement n’est pas disponible dans le pays sélectionné.']);
        }

        $phone = null;

        if (! empty($validated['phone'])) {
            $phone = $countries->normalize($country, $validated['dial_code'] ?? null, $validated['phone']);
        }

        if ($channel === 'mobile_money' && $phone === null) {
            throw ValidationException::withMessages(['phone' => 'Un numéro Mobile Money est obligatoire pour ce moyen de paiement.']);
        }

        $member = $this->member($request);

        $destination = DB::transaction(function () use ($member, $validated, $channel, $country, $phone, $auditLogger): PayoutDestination {
            $existing = PayoutDestination::query()->where('user_id', $member->id)->lockForUpdate()->first();
            $before = $existing?->snapshot();

            $destination = PayoutDestination::query()->updateOrCreate(['user_id' => $member->id], [
                'method' => $validated['method'],
                'channel' => $channel,
                'country' => $country,
                'phone' => $phone,
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
