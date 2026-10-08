<?php

namespace App\Domain\Audit;

use Illuminate\Support\Str;

/**
 * Masks secrets (passwords, tokens, OTP, keys) before data reaches audit or security journals.
 */
class SensitiveDataRedactor
{
    public const MASK = '[REDACTED]';

    /**
     * Key fragments that trigger masking, compared case-insensitively.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'secret',
        'token',
        'otp',
        'api_key',
        'private_key',
        'authorization',
        'code_hash',
    ];

    /**
     * @param  array<array-key, mixed>|null  $data
     * @return array<array-key, mixed>|null
     */
    public function redact(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        return Str::contains(Str::lower($key), self::SENSITIVE_KEY_FRAGMENTS);
    }
}
