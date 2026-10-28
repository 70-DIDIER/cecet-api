<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Actions\SubmitIdentityVerification;
use App\Domain\Identity\Enums\IdentityDocumentType;
use App\Domain\Identity\Enums\IdentityVerificationStatus;
use App\Domain\Identity\Http\Resources\IdentityVerificationResource;
use App\Domain\Identity\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The member's identity verification: the document and selfie sent, and the CECT's decision.
 * The member area takes the selfie with the camera; no photo is picked from the device.
 */
class IdentityVerificationController
{
    private const MAX_FILE_KILOBYTES = 5120;

    public function show(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $latest = IdentityVerification::query()->where('user_id', $member->id)->latest('created_at')->latest('id')->first();

        return response()->json(['data' => [
            'status' => $this->status($member, $latest),
            'identity_verified_at' => $member->identity_verified_at?->toIso8601ZuluString(),
            'document_types' => array_map(fn (IdentityDocumentType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'requires_back_side' => $type->requiresBackSide(),
            ], IdentityDocumentType::cases()),
            'latest' => $latest === null ? null : (new IdentityVerificationResource($latest))->resolve($request),
        ]]);
    }

    public function store(Request $request, SubmitIdentityVerification $submit): JsonResponse
    {
        $member = $this->member($request);
        $image = ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_FILE_KILOBYTES];

        $validated = $request->validate([
            'document_type' => ['required', Rule::enum(IdentityDocumentType::class)],
            'document_number' => ['nullable', 'string', 'max:60'],
            'front' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.self::MAX_FILE_KILOBYTES],
            'back' => [
                Rule::requiredIf(fn (): bool => IdentityDocumentType::tryFrom((string) $request->input('document_type'))?->requiresBackSide() ?? false),
                'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.self::MAX_FILE_KILOBYTES,
            ],
            'selfie' => ['required', ...$image],
        ]);

        $type = IdentityDocumentType::from($validated['document_type']);

        $verification = $submit->handle(
            $member,
            $type,
            $validated['document_number'] ?? null,
            $request->file('front'),
            $type->requiresBackSide() ? $request->file('back') : null,
            $request->file('selfie'),
        );

        return (new IdentityVerificationResource($verification))->response()->setStatusCode(201);
    }

    private function status(User $member, ?IdentityVerification $latest): string
    {
        if ($member->hasVerifiedIdentity()) {
            return IdentityVerificationStatus::Approved->value;
        }

        return $latest?->status->value ?? 'not_submitted';
    }

    private function member(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isMember(), 403);

        return $user;
    }
}
