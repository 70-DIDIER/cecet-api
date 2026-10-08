<?php

namespace App\Domain\Subscription\Console;

use App\Domain\Subscription\Actions\CancelStalePendingPacks;
use Illuminate\Console\Command;

class CancelStalePendingPacksCommand extends Command
{
    protected $signature = 'packs:cancel-stale-pending';

    protected $description = 'Annule les packs souscrits mais non payés dans le délai configuré';

    public function handle(CancelStalePendingPacks $cancel): int
    {
        $this->info("{$cancel->handle()} pack(s) impayé(s) annulé(s).");

        return self::SUCCESS;
    }
}
