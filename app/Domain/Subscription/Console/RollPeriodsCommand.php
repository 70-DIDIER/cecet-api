<?php

namespace App\Domain\Subscription\Console;

use App\Domain\Subscription\PeriodRoller;
use Illuminate\Console\Command;

class RollPeriodsCommand extends Command
{
    protected $signature = 'packs:roll-periods';

    protected $description = 'Crédite les périodes de pack échues, expire le quota non cumulable et applique la règle de fin de durée';

    public function handle(PeriodRoller $roller): int
    {
        $result = $roller->run(now());

        $this->info("{$result['credited']} période(s) créditée(s), {$result['expired_packs']} pack(s) arrivé(s) à terme.");

        if ($result['failures'] > 0) {
            $this->error("{$result['failures']} pack(s) en échec : voir les journaux applicatifs. Le prochain passage réessaiera.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
