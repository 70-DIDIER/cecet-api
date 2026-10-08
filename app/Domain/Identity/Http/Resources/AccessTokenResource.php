<?php

namespace App\Domain\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

/**
 * Newly issued bearer token. The plain-text token is only ever returned once.
 *
 * @mixin NewAccessToken
 */
class AccessTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => 'authenticated',
            'mode' => 'token',
            'access_token' => $this->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $this->accessToken->expires_at?->toIso8601ZuluString(),
            'user' => new UserResource($this->accessToken->tokenable?->loadMissing(['organization', 'pernumIdentities'])),
        ];
    }
}
