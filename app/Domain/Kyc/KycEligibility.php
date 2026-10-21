<?php

namespace App\Domain\Kyc;

use App\Domain\Kyc\Models\MemberKycCase;
use App\Models\User;

/**
 * Durable verification gate: user status alone is never sufficient.
 */
final class KycEligibility
{
    public static function approved(User $member): bool
    {
        if (! $member->isMember()) return false;
        $query = MemberKycCase::query()->where('user_id', $member->id)
            ->where('status', 'approved');
        if (config('kyc.provider') === 'didit') {
            // Manual document approval alone must never bypass video+liveness.
            $query->where('verification_mode', 'didit')
                ->where('provider_status', 'Approved')
                ->whereNotNull('provider_verified_at')
                ->where('provider_environment', config('kyc.didit.environment'));
            // Conclusive results must be documented in the case evidence checklist.
            $query->whereRaw("(provider_checks->>'complete')::boolean = true");
        }
        if (config('kyc.provider') === 'didit' && $member->person_type?->value === 'legal') {
            $query->whereRaw("(provider_checks->>'business_verified')::boolean = true");
        }
        return $query->exists();
    }
}
