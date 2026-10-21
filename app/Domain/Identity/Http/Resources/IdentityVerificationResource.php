<?php

namespace App\Domain\Identity\Http\Resources;

use App\Domain\Identity\Models\IdentityVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An identity verification. Staff also see who sent it and which files can be opened;
 * file paths on disk are never exposed.
 *
 * @mixin IdentityVerification
 */
class IdentityVerificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $forStaff = $request->user()?->isStaff() === true;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'document_type' => $this->document_type->value,
            'document_label' => $this->document_type->label(),
            'document_number' => $this->document_number,
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => $this->created_at->toIso8601ZuluString(),
            'reviewed_at' => $this->reviewed_at?->toIso8601ZuluString(),
            'files' => $this->when($forStaff, fn (): array => array_values(array_filter(
                IdentityVerification::FILES,
                fn (string $file): bool => $this->pathOf($file) !== null,
            ))),
            'member' => $this->when($forStaff && $this->relationLoaded('user'), fn (): array => [
                'id' => $this->user->id,
                'member_number' => $this->user->member_number,
                'person_type' => $this->user->person_type?->value,
                'first_name' => $this->user->first_name,
                'last_name' => $this->user->last_name,
                'legal_name' => $this->user->organization?->legal_name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
                'identity_verified_at' => $this->user->identity_verified_at?->toIso8601ZuluString(),
            ]),
            'reviewer' => $this->when($forStaff && $this->relationLoaded('reviewer'), fn (): ?array => $this->reviewer === null ? null : [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->full_name,
            ]),
        ];
    }
}
