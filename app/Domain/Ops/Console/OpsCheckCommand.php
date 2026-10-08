<?php

namespace App\Domain\Ops\Console;

use App\Domain\Ops\OpsMonitor;
use Illuminate\Console\Command;

class OpsCheckCommand extends Command
{
    protected $signature = 'ops:check {--integrity : Vérifier aussi les journaux et la chaîne d\'audit (plus coûteux)}';

    protected $description = 'Évalue la santé de la plateforme et déclenche les alertes d\'exploitation';

    public function handle(OpsMonitor $monitor): int
    {
        $failing = 0;

        foreach ($monitor->run((bool) $this->option('integrity')) as $check) {
            if ($check['ok']) {
                $this->line("OK      {$check['code']}");

                continue;
            }

            $failing++;
            $this->error(strtoupper($check['severity'])." {$check['code']} ({$check['value']}) : {$check['message']}");
        }

        return $failing === 0 ? self::SUCCESS : self::FAILURE;
    }
}
