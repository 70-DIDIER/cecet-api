<?php

namespace App\Domain\Kyc\Jobs;

use App\Domain\Kyc\Didit\DiditDecisionProcessor;
use App\Domain\Kyc\Models\MemberKycCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessDiditDecision implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 45;

    public function __construct(public readonly string $caseId) {}

    public function backoff(): array
    {
        return [15, 90, 300];
    }

    public function handle(DiditDecisionProcessor $processor): void
    {
        $case = MemberKycCase::query()->find($this->caseId);
        if ($case && $case->verification_mode === 'didit' && $case->provider_reference) {
            $processor->sync($case);
        }
    }
}
