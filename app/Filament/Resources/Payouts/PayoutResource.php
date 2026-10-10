<?php

namespace App\Filament\Resources\Payouts;

use App\Domain\Backoffice\Support\Format;
use App\Domain\Backoffice\Support\Labels;
use App\Domain\Payout\Enums\PayoutBatchStatus;
use App\Domain\Payout\Enums\PayoutRoute;
use App\Domain\Payout\Models\PayoutBatch;
use App\Filament\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Resources\Payouts\Pages\ViewPayout;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PayoutResource extends Resource
{
    protected static ?string $model = PayoutBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Opérations';

    protected static ?string $navigationLabel = 'Versements';

    protected static ?string $modelLabel = 'versement';

    protected static ?string $pluralModelLabel = 'versements';

    protected static ?string $slug = 'versements';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('payouts.view') === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * @return Builder<PayoutBatch>
     */
    public static function getEloquentQuery(): Builder
    {
        return PayoutBatch::query()->with(['beneficiary', 'payable']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = PayoutBatch::query()->whereIn('status', [PayoutBatchStatus::Failed, PayoutBatchStatus::ManualRequired])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function statusColor(PayoutBatchStatus $status): string
    {
        return match ($status) {
            PayoutBatchStatus::Paid => 'success',
            PayoutBatchStatus::Failed, PayoutBatchStatus::ManualRequired => 'danger',
            default => 'warning',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->label('Référence')->searchable()->weight('bold')->description(fn (PayoutBatch $record): string => Format::date($record->created_at)),
                TextColumn::make('beneficiary.first_name')
                    ->label('Bénéficiaire')
                    ->state(fn (PayoutBatch $record): string => $record->beneficiary->full_name)
                    ->description(fn (PayoutBatch $record): string => (string) $record->beneficiary->member_number),
                TextColumn::make('total_amount')->label('Total')->alignEnd()->state(fn (PayoutBatch $record): string => Format::xof($record->total_amount))->visibleFrom('lg'),
                TextColumn::make('remaining_amount')->label('Reste dû')->alignEnd()->weight('bold')->state(fn (PayoutBatch $record): string => Format::xof($record->remaining_amount)),
                TextColumn::make('route')->label('Mode')->visibleFrom('xl')->state(fn (PayoutBatch $record): string => $record->route === PayoutRoute::MobileMoney ? 'Mobile Money' : 'Règlement manuel'),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (PayoutBatchStatus $state): string => Labels::label('payout', $state->value))
                    ->color(fn (PayoutBatchStatus $state): string => self::statusColor($state)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(collect(PayoutBatchStatus::cases())->mapWithKeys(fn (PayoutBatchStatus $status): array => [$status->value => Labels::label('payout', $status->value)])->all()),
                SelectFilter::make('route')
                    ->label('Mode')
                    ->options([PayoutRoute::MobileMoney->value => 'Mobile Money', PayoutRoute::Manual->value => 'Règlement manuel']),
            ])
            ->recordUrl(fn (PayoutBatch $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Aucun versement');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayouts::route('/'),
            'view' => ViewPayout::route('/{record}'),
        ];
    }
}
