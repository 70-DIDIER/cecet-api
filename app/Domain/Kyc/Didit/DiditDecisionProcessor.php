<?php

namespace App\Domain\Kyc\Didit;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Kyc\Models\MemberKycCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Durable, idempotent state transition driven by Didit's server-to-server
 * decision endpoint; never trusts a browser redirect or unchecked webhook body.
 */
final class DiditDecisionProcessor
{
    public function __construct(private readonly DiditGateway $didit, private readonly AuditLogger $audit) {}

    public function sync(MemberKycCase $case): MemberKycCase
    {
        abort_unless($case->verification_mode === 'didit' && $case->provider_reference, 409);
        $response = $this->didit->decision($case->provider_reference);
        $status = $response['status'];
        $checks = $this->didit->checks($response);
        if (isset($response['environment']) && $response['environment'] !== config('kyc.didit.environment')) {
            abort(409, 'La session Didit provient d’un environnement différent.');
        }
        if (isset($response['session_id']) && $response['session_id'] !== $case->provider_reference) {
            abort(409, 'Identifiant de session Didit incohérent.');
        }
        if (isset($response['workflow_id']) && $response['workflow_id'] !== $case->provider_workflow_id) {
            abort(409, 'Workflow de vérification Didit incorrect.');
        }
        if (isset($response['application_id']) && $response['application_id'] !== config('kyc.didit.application_id')) {
            abort(409, 'Application Didit incorrecte.');
        }
        if (isset($response['vendor_data']) && $response['vendor_data'] !== 'cect-kyc:'.$case->id) {
            abort(409, 'Référence de dossier Didit incohérente.');
        }
        // Sandbox never authorizes a real CECT membership outside isolated tests.
        $canApprove = $status === 'Approved' && $checks['complete']
            && ($case->user?->person_type?->value !== 'legal' || $checks['business_verified'])
            && $case->provider_environment === config('kyc.didit.environment')
            && (config('kyc.didit.environment') === 'live' || app()->environment('testing'));
        DB::transaction(function () use ($case, $status, $checks, $canApprove): void {
            $locked = MemberKycCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless($locked->provider_reference === $case->provider_reference, 409);
            $user = User::query()->lockForUpdate()->findOrFail($locked->user_id);
            $old = $locked->status;
            $next = match (true) {
                $canApprove && config('kyc.didit.auto_approve') && $user->person_type?->value === 'natural' => 'approved',
                $canApprove => 'submitted', // validator reviews risk / PM documents
                $status === 'Declined' => 'needs_information',
                $status === 'Approved' => 'submitted', // incomplete evidence goes to human, not payments
                $status === 'In Review' => 'submitted',
                default => 'provider_pending',
            };
            if ($old === 'approved' && $canApprove) $next = 'approved';
            $locked->forceFill([
                'provider_status' => $status,
                'provider_checks' => $checks,
                'provider_verified_at' => $canApprove ? now() : null,
                'provider_reviewed_at' => now(),
                'status' => $next,
                'review_reason' => $next === 'needs_information'
                    ? 'La vérification Didit nécessite une nouvelle tentative.' : null,
            ])->save();
            if ($next === 'approved' && $user->status === UserStatus::PendingKyc) {
                $user->forceFill(['status' => UserStatus::PendingPayment])->save();
            }
            if ($old === 'approved' && $next !== 'approved' && $user->status === UserStatus::PendingPayment) {
                $user->forceFill(['status' => UserStatus::PendingKyc])->save();
            }
            if ($old !== $next) {
                $this->audit->record('kyc.didit_status_updated', $locked,
                    before: ['status' => $old],
                    after: ['status' => $next, 'document' => $checks['document'],
                        'active_liveness' => $checks['active_liveness'], 'face_match' => $checks['face_match']]);
            }
        });
        return $case->refresh();
    }
}
