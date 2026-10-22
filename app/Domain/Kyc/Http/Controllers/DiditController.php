<?php

namespace App\Domain\Kyc\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Kyc\Didit\DiditDecisionProcessor;
use App\Domain\Kyc\Didit\DiditGateway;
use App\Domain\Kyc\KycEligibility;
use App\Domain\Kyc\Models\MemberKycCase;
use App\Domain\Kyc\Jobs\ProcessDiditDecision;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DiditController
{
    public function start(Request $request, DiditGateway $gateway, AuditLogger $audit): JsonResponse
    {
        $member = $request->user();
        abort_unless($member instanceof User && $member->isMember()
            && in_array($member->status, [UserStatus::PendingKyc, UserStatus::PendingPayment], true)
            && ! KycEligibility::approved($member), 403);
        $request->validate(['privacy_notice_accepted' => ['required', 'accepted']]);
        abort_unless(config('kyc.provider') === 'didit', 503, 'Le parcours Didit n’est pas actif.');
        $result = DB::transaction(function () use ($member, $gateway, $audit): array {
            User::query()->lockForUpdate()->findOrFail($member->id);
            $case = MemberKycCase::query()->where('user_id', $member->id)->lockForUpdate()->first();
            if ($case && $case->verification_mode === 'didit'
                && in_array($case->status, ['provider_pending', 'submitted'], true)
                && $case->provider_reference && $case->provider_session_url) {
                return ['url' => $case->provider_session_url, 'status' => $case->status];
            }
            abort_if($case && ! in_array($case->status, ['rejected','needs_information','provider_pending'], true), 409,
                'Ce dossier doit être traité avant une nouvelle vérification.');
            $case ??= new MemberKycCase(['user_id' => $member->id]);
            $case->id ??= (string) Str::ulid();
            $documentOnlyTest = config('kyc.didit.environment') === 'sandbox'
                && config('kyc.didit.document_only_test');
            $created = $gateway->create($case,
                $member->person_type?->value === 'legal' && ! $documentOnlyTest);
            $case->forceFill([
                'verification_mode' => 'didit',
                'status' => 'provider_pending',
                'identity_payload' => null,
                'document_front_path' => null,
                'document_back_path' => null,
                'company_document_path' => null,
                'privacy_notice_accepted' => true,
                'submitted_at' => now(),
                'provider_reference' => $created['session_id'],
                'provider_session_url' => $created['url'],
                'provider_environment' => config('kyc.didit.environment'),
                'provider_workflow_id' => $created['workflow_id'],
                'provider_status' => 'Not Started',
                'provider_checks' => [],
                'provider_verified_at' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_reason' => null,
            ])->save();
            $audit->record('kyc.didit_session_created', $case, after: [
                'provider' => 'didit', 'status' => 'provider_pending',
            ], actor: $member);
            return ['url' => $created['url'], 'status' => 'provider_pending'];
        });
        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    /**
     * Reconciliation if webhook delivery is delayed. Authenticated and rate-limited;
     * the only decision authority is still Didit's authenticated decision endpoint.
     */
    public function refresh(Request $request, DiditDecisionProcessor $processor): JsonResponse
    {
        $member = $request->user();
        abort_unless($member instanceof User && $member->isMember(), 403);
        $case = MemberKycCase::query()->where('user_id', $member->id)->firstOrFail();
        abort_unless($case->verification_mode === 'didit', 409);
        $processor->sync($case);
        return response()->json(['data' => [
            'status' => $case->refresh()->status,
            'provider_status' => $case->provider_status,
        ]])->header('Cache-Control', 'no-store');
    }

    /**
     * Verifies a full-body HMAC before reading the payload. Matching a user by
     * vendor_data is forbidden: the session id must exist in CECT's database.
     */
    public function webhook(Request $request, DiditGateway $gateway): JsonResponse
    {
        abort_unless(config('kyc.provider') === 'didit', 404);
        $raw = $request->getContent();
        abort_unless(strlen($raw) < 262144 && $gateway->signatureValid(
            $raw, $request->header('X-Signature-V2'), $request->header('X-Signature')
        ), 401, 'Signature Didit invalide.');
        $data = json_decode($raw, true);
        abort_unless(is_array($data), 400);
        $timestamp = $data['timestamp'] ?? null;
        $headerTime = $request->header('X-Timestamp');
        abort_unless(is_numeric($timestamp) && is_string($headerTime)
            && ctype_digit($headerTime) && (int) $headerTime === (int) $timestamp
            && abs(time() - (int) $timestamp) <= 300, 401,
            'Notification Didit périmée.');
        $expectedApplication = config('kyc.didit.application_id');
        abort_unless(is_string($expectedApplication) && $expectedApplication !== ''
            && ($data['application_id'] ?? null) === $expectedApplication, 409,
            'Webhook Didit d’une autre application.');
        abort_unless(($data['environment'] ?? null) === config('kyc.didit.environment'), 409,
            'Événement d’un environnement Didit différent.');
        // Official console sample webhooks must not mutate real member identities.
        if ($request->header('X-Didit-Test-Webhook') === 'true') {
            return response()->json(['received' => true, 'test' => true]);
        }
        $event = $data['webhook_type'] ?? $data['event_type'] ?? $data['event'] ?? null;
        if ($event !== 'status.updated') {
            return response()->json(['received' => true]);
        }
        $session = $data['session_id'] ?? null;
        abort_unless(is_string($session) && strlen($session) >= 12, 400);
        $case = MemberKycCase::query()->where('provider_reference', $session)
            ->where('verification_mode', 'didit')->first();
        if (! $case) return response()->json(['received' => true]);
        ProcessDiditDecision::dispatch($case->id);
        return response()->json(['received' => true]);
    }
}
