<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Actions\ReviewIdentityVerification;
use App\Domain\Identity\Enums\IdentityVerificationStatus;
use App\Domain\Identity\Http\Resources\IdentityVerificationResource;
use App\Domain\Identity\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Back-office review of the identity documents and selfies sent by members.
 */
class IdentityVerificationAdminController
{
    public function __construct(private readonly ReviewIdentityVerification $review) {}

    /**
     * Verifications to review, oldest pending first by default.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(IdentityVerificationStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $validated['status'] ?? IdentityVerificationStatus::Pending->value;
        $search = $validated['search'] ?? null;

        $verifications = IdentityVerification::query()
            ->with(['user.organization', 'reviewer'])
            ->where('status', $status)
            ->when($search !== null, fn (Builder $query) => $query->whereHas('user', fn (Builder $user) => $user
                ->where('email', 'ilike', "%{$search}%")
                ->orWhere('member_number', 'ilike', "%{$search}%")
                ->orWhere('first_name', 'ilike', "%{$search}%")
                ->orWhere('last_name', 'ilike', "%{$search}%")))
            ->orderBy('created_at', $status === IdentityVerificationStatus::Pending->value ? 'asc' : 'desc')
            ->paginate($validated['per_page'] ?? 25);

        return IdentityVerificationResource::collection($verifications);
    }

    public function show(IdentityVerification $verification): IdentityVerificationResource
    {
        return new IdentityVerificationResource($verification->load(['user.organization', 'reviewer']));
    }

    /**
     * Streams one file of the verification (front, back or selfie). Every opening is audited.
     */
    public function file(Request $request, IdentityVerification $verification, string $file, AuditLogger $auditLogger): StreamedResponse
    {
        $path = $verification->pathOf($file);
        abort_if($path === null || ! Storage::disk(IdentityVerification::DISK)->exists($path), 404);

        $auditLogger->record('identity_verifications.file_viewed', $verification, metadata: [
            'file' => $file,
            'user_id' => $verification->user_id,
        ], actor: $this->actor($request));

        return Storage::disk(IdentityVerification::DISK)->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(Request $request, IdentityVerification $verification): IdentityVerificationResource
    {
        $verification = $this->review->approve($this->actor($request), $verification);

        return new IdentityVerificationResource($verification->load(['user.organization', 'reviewer']));
    }

    public function reject(Request $request, IdentityVerification $verification): IdentityVerificationResource
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason'];

        $verification = $this->review->reject($this->actor($request), $verification, $reason);

        return new IdentityVerificationResource($verification->load(['user.organization', 'reviewer']));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
