<?php

namespace App\Filament\Resources\Payouts\Pages;

use App\Filament\Resources\Payouts\PayoutResource;
use Filament\Resources\Pages\ListRecords;

class ListPayouts extends ListRecords
{
    protected static string $resource = PayoutResource::class;

    protected ?string $subheading = 'Montants en FCFA dus aux vendeurs et aux marchands après la confirmation de leurs YEM.';
}
