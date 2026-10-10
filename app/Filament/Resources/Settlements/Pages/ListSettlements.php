<?php

namespace App\Filament\Resources\Settlements\Pages;

use App\Filament\Resources\Settlements\SettlementResource;
use Filament\Resources\Pages\ListRecords;

class ListSettlements extends ListRecords
{
    protected static string $resource = SettlementResource::class;

    protected ?string $subheading = 'Un règlement fait hors prestataire doit être approuvé par une autre personne que celle qui l\'a saisi.';
}
