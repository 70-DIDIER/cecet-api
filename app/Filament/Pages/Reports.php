<?php

namespace App\Filament\Pages;

use App\Domain\Reporting\ReportingQueries;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

class Reports extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Opérations';

    protected static ?string $navigationLabel = 'Rapports';

    protected static ?string $title = 'Rapports';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.reports';

    public string $period = '30';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reports.view') === true;
    }

    public function getSubheading(): ?string
    {
        return 'Activité de la coopérative sur la période : seuls les paiements confirmés sont comptés.';
    }

    /**
     * @return array<int|string, string>
     */
    public function periods(): array
    {
        return ['30' => '30 derniers jours', '90' => '90 derniers jours', 'year' => 'Depuis le 1er janvier', 'all' => 'Depuis le début'];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(): array
    {
        $to = CarbonImmutable::tomorrow();

        return match ($this->period) {
            'year' => [CarbonImmutable::now()->startOfYear(), $to],
            'all' => [CarbonImmutable::parse('2020-01-01'), $to],
            '90' => [$to->subDays(90), $to],
            default => [$to->subDays(30), $to],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        [$from, $to] = $this->bounds();

        return ['summary' => app(ReportingQueries::class)->summary($from, $to), 'from' => $from, 'to' => $to->subDay()];
    }
}
