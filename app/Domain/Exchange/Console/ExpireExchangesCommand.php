<?php

namespace App\Domain\Exchange\Console;

use App\Domain\Exchange\Actions\SellerExchangeService;
use Illuminate\Console\Command;

class ExpireExchangesCommand extends Command
{
    protected $signature = 'exchanges:expire';

    protected $description = 'Expire les demandes de vente restées sans transfert au-delà du délai de réservation et libère leur quota';

    public function handle(SellerExchangeService $exchanges): int
    {
        $this->info("{$exchanges->expireStale()} demande(s) expirée(s).");

        return self::SUCCESS;
    }
}
