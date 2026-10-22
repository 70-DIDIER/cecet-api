<?php

namespace App\Domain\Kyc;

use App\Domain\Kyc\Models\MemberKycCase;
use App\Models\User;

/**
 * Durable verification gate: user status alone is never sufficient.
 */
final class KycEligibility
{
    /**
     * Presentation-only completion state for the OCR-only Didit Sandbox.
     * Never use this in payment, membership activation, or YEM guards.
     */
    public static function sandboxDocumentTestCompleted(User $member): bool
    {
        if (! $member->isMember()
            || config('kyc.provider') !== 'didit'
            || config('kyc.didit.environment') !== 'sandbox'
            || ! config('kyc.didit.document_only_test')
            || ! filled(config('kyc.didit.workflow_id'))) {
            return false;
        }

        return MemberKycCase::query()
            ->where('user_id', $member->id)
            ->where('verification_mode', 'didit')
            ->where('provider_environment', 'sandbox')
            ->where('provider_workflow_id', config('kyc.didit.workflow_id'))
            ->whereNotNull('provider_reference')
            ->where('status', 'submitted')
            ->where('provider_status', 'Approved')
            ->where('provider_reviewed_at', '<=', now()->subMinutes(2))
            ->whereRaw("(provider_checks->>'document')::boolean = true")
            ->exists();
    }

    /**
     * Explicit permission to run the EXISTING checkout with its fake provider
     * during the CECT staging presentation, without treating Sandbox KYC
     * as a real identity approval. Never active in production.
     */
    public static function stagingMembershipDemo(User $member): bool
    {
        return app()->environment('staging')
            && app(\App\Domain\Settings\SettingsService::class)
                ->string('payment.inbound_provider') === 'fake'
            && self::sandboxDocumentTestCompleted($member);
    }

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
