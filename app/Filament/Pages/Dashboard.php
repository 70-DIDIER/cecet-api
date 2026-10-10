<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CollectionsChart;
use App\Filament\Widgets\OverviewStats;
use App\Filament\Widgets\PendingDecisions;
use App\Filament\Widgets\PlatformHealth;
use App\Filament\Widgets\RecentPayments;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Tableau de bord';

    protected static ?string $navigationLabel = 'Tableau de bord';

    protected static string|\UnitEnum|null $navigationGroup = 'Pilotage';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('days')
                ->label('Période')
                ->options([7 => '7 derniers jours', 30 => '30 derniers jours', 90 => '90 derniers jours'])
                ->default(30)
                ->native(false)
                ->selectablePlaceholder(false),
        ]);
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            PendingDecisions::class,
            OverviewStats::class,
            CollectionsChart::class,
            RecentPayments::class,
            PlatformHealth::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
