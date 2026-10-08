<?php

namespace App\Domain\Settings\Console;

use App\Domain\Settings\Actions\SyncSettingsCatalog;
use Illuminate\Console\Command;

class SyncSettingsCatalogCommand extends Command
{
    protected $signature = 'settings:sync';

    protected $description = 'Synchronise le catalogue des paramètres et initialise les paramètres jamais configurés';

    public function handle(SyncSettingsCatalog $sync): int
    {
        $result = $sync->handle();

        $this->info("{$result['definitions']} définitions synchronisées.");

        foreach ($result['initialized'] as $key) {
            $this->line("  initialisé : {$key}");
        }

        foreach ($result['orphaned'] as $key) {
            $this->warn("  absent du catalogue (conservé pour l'historique) : {$key}");
        }

        return self::SUCCESS;
    }
}
