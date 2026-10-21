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
        return $member->isMember() && MemberKycCase::query()
            ->where('user_id', $member->id)
            ->where('status', 'approved')
            ->exists();
    }
}
