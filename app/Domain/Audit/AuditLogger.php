<?php

namespace App\Domain\Audit;

use App\Domain\Audit\Enums\AuditResult;
use App\Domain\Audit\Models\AuditEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Writes business audit events. Call it inside the same database transaction as the change it records,
 * so that the change and its audit trail commit or roll back together.
 */
class AuditLogger
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Request $request,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        AuditResult $result = AuditResult::Success,
        ?string $reason = null,
        array $metadata = [],
        ?Authenticatable $actor = null,
    ): AuditEvent {
        $actor ??= $this->auth->guard()->user();

        $event = AuditEvent::query()->create([
            'occurred_at' => now(),
            'actor_type' => $actor !== null ? AuditEvent::ACTOR_USER : AuditEvent::ACTOR_SYSTEM,
            'actor_id' => $actor?->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject !== null ? (string) $subject->getKey() : null,
            'result' => $result,
            'before' => $this->redactor->redact($before),
            'after' => $this->redactor->redact($after),
            'metadata' => $metadata === [] ? null : $this->redactor->redact($metadata),
            'reason' => $reason,
            'correlation_id' => Context::get('correlation_id'),
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);

        return $event->refresh();
    }
}
