<?php

namespace App\Filament\Widgets;

use App\Domain\Reporting\ReportingQueries;
use App\Filament\Support\DashboardPeriod;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class CollectionsChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Encaissements';

    protected ?string $description = 'Montants confirmés par jour';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()?->can('reports.view') === true;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        [$from, $to] = DashboardPeriod::bounds($this->pageFilters);
        $series = app(ReportingQueries::class)->dailySeries($from, $to);

        return [
            'datasets' => [
                [
                    'label' => 'FCFA',
                    'data' => $series['collected_xof'],
                    'backgroundColor' => '#0b6b45',
                    'borderRadius' => 6,
                    'maxBarThickness' => 22,
                ],
            ],
            'labels' => array_map(fn (string $day): string => CarbonImmutable::parse($day)->translatedFormat('d M'), $series['days']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'grid' => ['color' => '#e9efef']], 'x' => ['grid' => ['display' => false]]],
        ];
    }
}
