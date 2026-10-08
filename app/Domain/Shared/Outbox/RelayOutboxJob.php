<?php

namespace App\Domain\Shared\Outbox;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers pending outbox messages soon after a commit. The scheduled "outbox:relay" command is the safety net.
 */
class RelayOutboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 30;

    public function handle(Outbox $outbox): void
    {
        $outbox->relay();
    }
}
