<?php

namespace App\Domain\Audit;

use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Writes security events (login attempts, OTP failures, privilege changes, invalid webhooks).
 */
class SecurityEventLogger
{
    public function __construct(
        private readonly Request $request,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $eventType,
        SecuritySeverity $severity = SecuritySeverity::Info,
        ?string $userId = null,
        ?string $identifier = null,
        array $metadata = [],
    ): SecurityEvent {
        return SecurityEvent::query()->create([
            'occurred_at' => now(),
            'event_type' => $eventType,
            'severity' => $severity,
            'user_id' => $userId,
            'identifier' => $identifier,
            'metadata' => $metadata === [] ? null : $this->redactor->redact($metadata),
            'correlation_id' => Context::get('correlation_id'),
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }
}
