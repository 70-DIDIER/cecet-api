<?php

namespace App\Domain\Payout\Console;

use App\Domain\Payout\Actions\PayoutOperations;
use Illuminate\Console\Command;

class RefreshPayoutsCommand extends Command
{
    protected $signature = 'payouts:refresh';

    protected $description = 'Interroge le prestataire sur les virements en cours et met à jour les payouts';

    public function handle(PayoutOperations $operations): int
    {
        $this->info("{$operations->refresh()} virement(s) mis à jour.");

        return self::SUCCESS;
    }
}
