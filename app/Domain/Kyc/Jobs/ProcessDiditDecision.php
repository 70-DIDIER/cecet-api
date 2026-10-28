<?php

namespace App\Domain\Kyc\Jobs;

use App\Domain\Kyc\Didit\DiditDecisionProcessor;
use App\Domain\Kyc\Models\MemberKycCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Poll the authenticated provider decision after document upload, even when
 * the member closes their browser. Sandbox-only follow-up never grants
 * membership or payment access.
 */
final class ProcessDiditDecision implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 45;

    public function __construct(
        public readonly string $caseId,
        public readonly ?string $expectedSessionId = null,
        public readonly int $pollAttempt = 0,
    ) {}

    public function backoff(): array
    {
        return [15, 90, 300];
    }

    public function handle(DiditDecisionProcessor $processor): void
    {
        $case = MemberKycCase::query()->find($this->caseId);
        if (! $case || $case->verification_mode !== 'didit' || ! $case->provider_reference) {
            return;
        }

        // A previous delayed job must never apply a verdict to a replaced
        // session (for example after a member restarts the Sandbox test).
        if ($this->expectedSessionId !== null
            && $case->provider_reference !== $this->expectedSessionId) {
            return;
        }

        $case = $processor->sync($case);

        // No background polling in Live until the provider's signed webhook
        // or another explicitly authorized production reconciliation path.
        // Keep test sessions bounded to avoid endless provider requests.
        if (config('kyc.didit.environment') !== 'sandbox'
            || ! config('kyc.didit.document_only_test')
            || app()->environment('testing')
            || $this->pollAttempt >= 14
            || ! $case->submitted_at
            || $case->submitted_at->lt(now()->subHour())) {
            return;
        }

        // Poll only while a final Didit decision has not arrived.
        if (in_array($case->provider_status,
            ['Approved', 'Declined', 'Expired', 'Abandoned', 'Kyc Expired'], true)) {
            return;
        }

        if (! in_array($case->status, ['approved', 'rejected', 'needs_information'], true)) {
            self::dispatch($case->id, $case->provider_reference, $this->pollAttempt + 1)
                ->delay(now()->addMinutes(2));
        }
    }
}
