<?php

namespace App\Domain\Reporting\Jobs;

use App\Domain\Reporting\Snapshots\SnapshotGenerator;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Snapshot asked from the back-office, generated in the background.
 */
class GenerateSnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(public readonly ?User $requestedBy = null) {}

    public function handle(SnapshotGenerator $generator): void
    {
        $generator->generate('manual', $this->requestedBy);
    }
}
