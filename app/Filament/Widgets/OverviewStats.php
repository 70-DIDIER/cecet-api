<?php

namespace App\Filament\Widgets;

use App\Domain\Backoffice\Support\Format;
use App\Domain\Reporting\ReportingQueries;
use App\Filament\Support\DashboardPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('reports.view') === true;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        [$from, $to] = DashboardPeriod::bounds($this->pageFilters);
        $queries = app(ReportingQueries::class);
        $summary = $queries->summary($from, $to);
        $kpis = $summary['kpis'];

        $length = (int) $from->diffInDays($to);
        $previous = (int) $queries->summary($from->subDays($length), $from)['kpis']['total_collected_xof'];
        $collected = (int) $kpis['total_collected_xof'];

        $collectedStat = Stat::make('Total collecté', Format::xof($collected));

        if ($previous > 0) {
            $percent = (int) round(($collected - $previous) * 100 / $previous);
            $collectedStat
                ->description(($percent >= 0 ? '+' : '−').abs($percent).' % par rapport à la période précédente')
                ->descriptionIcon($percent >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($percent >= 0 ? 'success' : 'danger');
        }

        return [
            $collectedStat,
            Stat::make('Nouveaux membres', Format::number($kpis['new_members']))
                ->description(Format::number($kpis['registrations']).' inscription(s) sur la période'),
            Stat::make('Paiements confirmés', Format::number($kpis['confirmed_payments']))
                ->description('Panier moyen : '.Format::xof($kpis['average_payment_xof'])),
            Stat::make('YEM en circulation', Format::yem($kpis['yem_in_circulation']))
                ->description(Format::number($queries->overview()['active_members']).' membres actifs'),
        ];
    }
}
