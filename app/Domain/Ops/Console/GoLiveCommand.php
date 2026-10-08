<?php

namespace App\Domain\Ops\Console;

use App\Domain\Ops\GoLiveChecker;
use Illuminate\Console\Command;

class GoLiveCommand extends Command
{
    protected $signature = 'ops:golive';

    protected $description = 'Contrôle la configuration avant la mise en production (checklist §33, partie automatisable)';

    public function handle(GoLiveChecker $checker): int
    {
        $failing = 0;

        foreach ($checker->check() as $item) {
            if ($item['ok']) {
                $this->line("OK      {$item['code']}");

                continue;
            }

            $failing++;
            $this->error("BLOQUANT {$item['code']} : {$item['message']}");
        }

        $this->newLine();
        $this->line('Restent des décisions humaines : taux et packs ratifiés, contrat prestataire, pentest/DAST, restauration testée, recette responsive.');

        return $failing === 0 ? self::SUCCESS : self::FAILURE;
    }
}
