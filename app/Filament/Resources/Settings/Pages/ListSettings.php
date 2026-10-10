<?php

namespace App\Filament\Resources\Settings\Pages;

use App\Filament\Resources\Settings\SettingResource;
use Filament\Resources\Pages\ListRecords;

class ListSettings extends ListRecords
{
    protected static string $resource = SettingResource::class;

    protected static ?string $title = 'Configuration';

    protected ?string $subheading = 'Tarifs, frais, durées et seuils appliqués par la plateforme. Chaque modification crée une version motivée, qui peut être programmée.';
}
