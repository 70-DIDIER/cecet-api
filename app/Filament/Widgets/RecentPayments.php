<?php

namespace App\Filament\Widgets;

use App\Domain\Backoffice\Support\Format;
use App\Domain\Reporting\ReportingQueries;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentPayments extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('reports.view') === true;
    }

    protected function getTableHeading(): string
    {
        return 'Derniers paiements';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => collect(app(ReportingQueries::class)->recentPayments(8))->keyBy('id')->all())
            ->paginated(false)
            ->emptyStateHeading('Aucun paiement confirmé')
            ->columns([
                TextColumn::make('member')->label('Membre')->weight('bold'),
                TextColumn::make('label')->label('Objet'),
                TextColumn::make('method')->label('Moyen')->formatStateUsing(fn (?string $state): string => match ($state) {
                    'TMONEY' => 'T-Money',
                    'FLOOZ' => 'Flooz',
                    'CASH' => 'Espèces',
                    'BANK_TRANSFER' => 'Virement',
                    null => '—',
                    default => $state,
                }),
                TextColumn::make('date')->label('Date')->formatStateUsing(fn ($state): string => CarbonImmutable::parse($state)->translatedFormat('d M Y, H:i')),
                TextColumn::make('amount_xof')->label('Montant')->alignEnd()->weight('bold')->formatStateUsing(fn ($state): string => Format::xof((int) $state)),
            ]);
    }
}
