<?php

namespace App\Domain\Audit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the audit hash chain in the database and reports the first broken link, if any.
 */
class VerifyAuditChainCommand extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Vérifie l\'intégrité de la chaîne de hash du journal d\'audit';

    public function handle(): int
    {
        $rows = DB::cursor(<<<'SQL'
            SELECT
                e.position,
                e.hash,
                e.prev_hash,
                row_number() OVER (ORDER BY e.position) AS expected_position,
                lag(e.hash) OVER (ORDER BY e.position) AS expected_prev_hash,
                audit_event_hash(e, lag(e.hash) OVER (ORDER BY e.position)) AS expected_hash
            FROM audit_events e
            ORDER BY e.position
        SQL);

        $checked = 0;

        foreach ($rows as $row) {
            $checked++;

            $isIntact = (int) $row->position === (int) $row->expected_position
                && $row->prev_hash === $row->expected_prev_hash
                && $row->hash === $row->expected_hash;

            if (! $isIntact) {
                $this->error("Chaîne d'audit rompue à la position {$row->position}.");

                return self::FAILURE;
            }
        }

        $this->info("Chaîne d'audit intègre ({$checked} événements vérifiés).");

        return self::SUCCESS;
    }
}
