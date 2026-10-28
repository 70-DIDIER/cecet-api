<?php

namespace App\Domain\Identity\Http\Resources;

use App\Domain\Identity\Models\PernumIdentity;
use App\Models\User;
use App\Support\YemQuantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'person_type' => $this->person_type?->value,
            'member_number' => $this->member_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status->value,
            'email_verified_at' => $this->email_verified_at?->toIso8601ZuluString(),
            'identity_verified_at' => $this->when(! $this->isStaff(), fn () => $this->identity_verified_at?->toIso8601ZuluString()),
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization === null ? null : [
                'id' => $this->organization->id,
                'legal_name' => $this->organization->legal_name,
                'legal_form' => $this->organization->legal_form,
                'rccm' => $this->organization->rccm,
                'nif' => $this->organization->nif,
            ]),
            'pernums' => $this->whenLoaded('pernumIdentities', fn () => $this->pernumIdentities->map(fn (PernumIdentity $identity): array => [
                'id' => $identity->id,
                'pernum' => $identity->pernum,
                'kind' => $identity->kind->value,
                'status' => $identity->status->value,
            ])->all()),
            'pernum' => $this->when(! $this->isStaff() && $this->relationLoaded('pernumIdentities'), fn () => $this->pernumIdentities->first(fn (PernumIdentity $identity): bool => $identity->status->value !== 'revoked')?->pernum),
            'membership_status' => $this->when(array_key_exists('membership_status', $this->resource->getAttributes()), fn () => $this->resource->getAttribute('membership_status')),
            'active_families' => $this->when(array_key_exists('active_families', $this->resource->getAttributes()), fn () => array_values(array_filter(explode(',', (string) $this->resource->getAttribute('active_families'))))),
            'yem_balance' => $this->when(array_key_exists('yem_balance', $this->resource->getAttributes()), fn () => YemQuantity::of((string) $this->resource->getAttribute('yem_balance'))),
            'roles' => $this->when($this->isStaff(), fn () => $this->getRoleNames()->values()->all()),
            'permissions' => $this->when(
                $this->isStaff() && $request->user()?->is($this->resource),
                fn () => $this->isSuperadmin() ? ['*'] : $this->getAllPermissions()->pluck('name')->sort()->values()->all(),
            ),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
