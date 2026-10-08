<?php

namespace App\Domain\Shared\Console;

use App\Domain\Shared\Outbox\Outbox;
use Illuminate\Console\Command;

class OutboxRelayCommand extends Command
{
    protected $signature = 'outbox:relay {--limit=200 : Nombre maximal de messages traités}';

    protected $description = 'Délivre les messages en attente de la boîte d\'envoi transactionnelle (payouts, etc.)';

    public function handle(Outbox $outbox): int
    {
        $result = $outbox->relay((int) $this->option('limit'));

        $this->info("{$result['processed']} message(s) délivré(s), {$result['failed']} en échec (réessai programmé).");

        return self::SUCCESS;
    }
}
