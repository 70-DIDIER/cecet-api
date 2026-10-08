<?php

namespace App\Domain\Merchant\Console;

use App\Domain\Merchant\Actions\MerchantPaymentService;
use Illuminate\Console\Command;

class ExpireMerchantPaymentsCommand extends Command
{
    protected $signature = 'merchant-payments:expire';

    protected $description = 'Expire les paiements marchands jamais déclarés au-delà du délai de réservation et libère le quota du payeur';

    public function handle(MerchantPaymentService $payments): int
    {
        $this->info("{$payments->expireStale()} paiement(s) marchand(s) expiré(s).");

        return self::SUCCESS;
    }
}
