<?php

namespace App\Domain\Kyc\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Kyc\Models\MemberKycCase;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * KYC before membership payment. Files are PRIVATE: no public/storage URL,
 * no unguarded document endpoint, no external KYC approval without verification.
 */
class KycController
{
    public function mine(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $case = MemberKycCase::query()->where('user_id', $member->id)->first();

        return response()->json(['data' => [
            'account_status' => $member->status->value,
            'provider' => config('kyc.provider'),
            'provider_environment' => config('kyc.provider') === 'didit' ? config('kyc.didit.environment') : null,
            'kyc' => $case ? $this->memberSummary($case) : null,
        ]])->header('Cache-Control', 'no-store');
    }

    public function submit(Request $request, AuditLogger $audit): JsonResponse
    {
        $member = $this->member($request);
        abort_unless($member->status === UserStatus::PendingKyc, 403, 'Vérification KYC non disponible pour ce statut.');
        abort_unless(config('kyc.provider') === 'manual', 403,
            'Utilisez le parcours Didit avec capture biométrique en direct.');

        $data = $request->validate([
            'document_type' => ['required', Rule::in(['national_id', 'passport', 'residence_permit'])],
            'document_number' => ['required', 'string', 'min:4', 'max:60'],
            'document_country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'address' => ['required', 'string', 'min:8', 'max:300'],
            'document_front' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
            'document_back' => ['nullable', 'required_unless:document_type,passport', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
            'company_document' => [$member->person_type->value === 'legal' ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
            'privacy_notice_accepted' => ['required', 'accepted'],
        ]);
        $old = MemberKycCase::query()->where('user_id', $member->id)->first();
        abort_if($old && ! in_array($old->status, ['rejected', 'needs_information'], true), 409, 'Dossier déjà soumis.');
        $case = $old ?? new MemberKycCase(['user_id' => $member->id]);
        $previousPaths = $old ? array_filter([
            $old->document_front_path, $old->document_back_path, $old->company_document_path,
        ]) : [];
        $case->id ??= (string) \Illuminate\Support\Str::ulid();

        $paths = [];
        try {
            foreach (['document_front', 'document_back', 'company_document'] as $field) {
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $extension = $file->guessExtension();
                    abort_unless(in_array($extension, ['jpg', 'jpeg', 'png', 'pdf'], true), 422, 'Format de document non pris en charge.');
                    $path = 'kyc/'.$member->id.'/'.$case->id.'/'
                        .\Illuminate\Support\Str::uuid().'.'.$extension.'.enc';
                    $contents = file_get_contents($file->getRealPath());
                    if ($contents === false || ! Storage::disk('local')->put(
                        $path, Crypt::encryptString(base64_encode($contents))
                    )) {
                        throw new \RuntimeException('Échec de stockage chiffré du document.');
                    }
                    $paths[$field] = $path;
                }
            }
            DB::transaction(function () use ($case, $member, $data, $paths, $audit): void {
                $lockedMember = User::query()->lockForUpdate()->findOrFail($member->id);
                abort_unless($lockedMember->status === UserStatus::PendingKyc, 409, 'Le statut a changé.');
                $existing = MemberKycCase::query()->where('user_id', $member->id)->lockForUpdate()->first();
                abort_if($existing && ! in_array($existing->status, ['rejected', 'needs_information'], true), 409, 'Dossier déjà soumis.');

                $case->fill([
                    'status' => 'submitted',
                    'verification_mode' => 'manual',
                    'identity_payload' => [
                        'document_type' => $data['document_type'],
                        'document_number' => $data['document_number'],
                        'document_country' => $data['document_country'],
                        'date_of_birth' => $data['date_of_birth'],
                        'address' => $data['address'],
                    ],
                    'document_front_path' => $paths['document_front'],
                    'document_back_path' => $paths['document_back'] ?? null,
                    'company_document_path' => $paths['company_document'] ?? null,
                    'privacy_notice_accepted' => true,
                    'submitted_at' => now(),
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'review_reason' => null,
                    'provider_reference' => null,
                    'provider_reviewed_at' => null,
                ]);
                $case->save();
                $audit->record('kyc.submitted', $case, after: ['status' => 'submitted', 'person_type' => $member->person_type->value], actor: $member);
            });
        } catch (\Throwable $e) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
        // Resubmissions must not leave superseded identity documents indefinitely.
        // Deletion occurs after the transaction commits; a failed deletion is logged
        // without invalidating the new committed case or erasing its new documents.
        foreach ($previousPaths as $oldPath) {
            try {
                Storage::disk('local')->delete($oldPath);
            } catch (\Throwable $error) {
                report($error);
            }
        }

        return response()->json(['data' => $this->memberSummary($case->refresh())], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function queue(Request $request): JsonResponse
    {
        $this->reviewer($request);
        $status = $request->validate(['status' => ['nullable', Rule::in(['submitted', 'approved', 'rejected', 'needs_information'])]])['status'] ?? 'submitted';
        $cases = MemberKycCase::query()->with('user:id,first_name,last_name,email,person_type,member_number')
            ->where('status', $status)->orderBy('submitted_at')->paginate(25);

        return response()->json(['data' => $cases->getCollection()->map(fn (MemberKycCase $case) => $this->reviewSummary($case)),
            'meta' => ['total' => $cases->total(), 'current_page' => $cases->currentPage(), 'last_page' => $cases->lastPage()]])
            ->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, MemberKycCase $case, AuditLogger $audit): JsonResponse
    {
        $this->reviewer($request);
        $case->load('user:id,first_name,last_name,email,person_type,member_number');
        $audit->record('kyc.identity_viewed', $case, actor: $request->user());
        $response = $this->reviewSummary($case);
        $response['identity'] = $case->identity_payload;
        return response()->json(['data' => $response])->header('Cache-Control', 'no-store');
    }

    public function document(Request $request, MemberKycCase $case, string $type, AuditLogger $audit): Response
    {
        $actor = $this->reviewer($request);
        abort_unless(in_array($type, ['front', 'back', 'company'], true), 404);
        $field = match ($type) {
            'front' => 'document_front_path',
            'back' => 'document_back_path',
            'company' => 'company_document_path',
        };
        $path = $case->{$field};
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        abort_unless(str_starts_with($path, 'kyc/'.$case->user_id.'/'.$case->id.'/'), 403);
        abort_unless(preg_match('/\.(jpg|jpeg|png|pdf)\.enc$/', $path, $ext), 404);

        $encoded = Crypt::decryptString(Storage::disk('local')->get($path));
        $bytes = base64_decode($encoded, true);
        abort_if($bytes === false, 500, 'Document KYC corrompu.');
        $mime = match ($ext[1]) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            default => 'image/jpeg',
        };
        $audit->record('kyc.document_accessed', $case, metadata: ['document_type' => $type], actor: $actor);

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="verification-'.$type.'.'.$ext[1].'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    public function decide(Request $request, MemberKycCase $case, AuditLogger $audit): JsonResponse
    {
        $actor = $this->reviewer($request);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected', 'needs_information'])],
            'reason' => ['required_unless:decision,approved', 'nullable', 'string', 'min:10', 'max:1000'],
        ]);
        DB::transaction(function () use ($case, $actor, $data, $audit): void {
            $locked = MemberKycCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless($locked->status === 'submitted', 409, 'Le dossier a déjà été traité.');
            $member = User::query()->lockForUpdate()->findOrFail($locked->user_id);
            abort_unless(in_array($member->status, [UserStatus::PendingKyc, UserStatus::PendingPayment], true),
                409, 'Le membre a changé de statut.');
            $decision = $data['decision'];
            if (config('kyc.provider') === 'didit' && $decision === 'approved') {
                $checks = $locked->provider_checks ?? [];
                abort_unless($locked->verification_mode === 'didit'
                    && $locked->provider_status === 'Approved'
                    && $locked->provider_verified_at
                    && ($checks['complete'] ?? false)
                    && ($member->person_type?->value !== 'legal' || ($checks['business_verified'] ?? false))
                    && $locked->provider_environment === config('kyc.didit.environment'),
                    409, 'Contrôles Didit document, vidéo et visage requis.');
            }
            $locked->forceFill([
                'status' => $decision,
                'reviewed_by' => $actor->id,
                'review_reason' => $data['reason'] ?? null,
                'reviewed_at' => now(),
            ])->save();
            if ($decision === 'approved') {
                $member->forceFill(['status' => UserStatus::PendingPayment])->save();
            }
            $audit->record('kyc.'.$decision, $locked, before: ['status' => 'submitted'],
                after: ['status' => $decision], reason: $data['reason'] ?? null, actor: $actor);
        });
        return response()->json(['data' => $this->reviewSummary($case->refresh()->load('user'))])
            ->header('Cache-Control', 'no-store');
    }

    private function member(Request $request): User
    {
        $member = $request->user();
        abort_unless($member instanceof User && $member->isMember(), 403);
        return $member;
    }

    private function reviewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isStaff() && $user->can('kyc.review'), 403);
        return $user;
    }

    private function memberSummary(MemberKycCase $case): array
    {
        return [
            'id' => $case->id,
            'status' => $case->status,
            'verification_mode' => $case->verification_mode,
            'provider_status' => $case->provider_status,
            'provider_checks' => $case->provider_checks,
            'can_resume' => $case->verification_mode === 'didit' && in_array($case->status, ['provider_pending','submitted'], true),
            'submitted_at' => $case->submitted_at?->toIso8601String(),
            'reviewed_at' => $case->reviewed_at?->toIso8601String(),
            'review_reason' => $case->status === 'approved' ? null : $case->review_reason,
        ];
    }

    private function reviewSummary(MemberKycCase $case): array
    {
        return [
            ...$this->memberSummary($case),
            'person' => [
                'name' => trim($case->user->first_name.' '.$case->user->last_name),
                'email' => $case->user->email,
                'person_type' => $case->user->person_type?->value,
                'member_number' => $case->user->member_number,
            ],
            'documents' => [
                'front' => (bool) $case->document_front_path,
                'back' => (bool) $case->document_back_path,
                'company' => (bool) $case->company_document_path,
            ],
            'verification_mode' => $case->verification_mode,
        ];
    }
}
