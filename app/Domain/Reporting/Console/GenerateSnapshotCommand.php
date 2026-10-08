<?php

namespace App\Domain\Reporting\Console;

use App\Domain\Reporting\Models\ExportSnapshot;
use App\Domain\Reporting\Snapshots\SnapshotGenerator;
use Illuminate\Console\Command;

class GenerateSnapshotCommand extends Command
{
    protected $signature = 'snapshots:generate {--force : Générer même sans nouvelle opération métier}';

    protected $description = 'Génère le snapshot consolidé si des opérations métier ont eu lieu depuis le précédent';

    public function handle(SnapshotGenerator $generator): int
    {
        if (! $this->option('force') && ! $generator->isDue()) {
            $this->info('Aucune nouvelle opération métier : pas de snapshot.');

            return self::SUCCESS;
        }

        $snapshot = $generator->generate($this->option('force') ? 'manual' : 'automatic');

        if ($snapshot === null) {
            $this->warn('Une génération est déjà en cours.');

            return self::SUCCESS;
        }

        if ($snapshot->status !== ExportSnapshot::READY) {
            $this->error("Échec du snapshot v{$snapshot->version} : {$snapshot->error}");

            return self::FAILURE;
        }

        $this->info("Snapshot v{$snapshot->version} généré (sha256 {$snapshot->sha256}).");

        return self::SUCCESS;
    }
}
