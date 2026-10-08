<?php

namespace App\Domain\Identity\Http\Resources;

use App\Domain\Identity\Models\OtpChallenge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Public view of an OTP challenge: never the code, and a masked destination.
 *
 * @mixin OtpChallenge
 */
class OtpChallengeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'challenge_id' => $this->id,
            'purpose' => $this->purpose->value,
            'sent_to' => self::maskEmail($this->sent_to),
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
        ];
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return Str::substr($local, 0, 1).str_repeat('*', max(Str::length($local) - 1, 3)).'@'.$domain;
    }
}
