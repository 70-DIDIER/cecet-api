<?php

namespace App\Filament\Resources\Settlements;

use App\Domain\Backoffice\Support\Format;
use App\Domain\Backoffice\Support\Labels;
use App\Domain\Payout\Actions\ManualSettlementService;
use App\Domain\Payout\Enums\ManualSettlementStatus;
use App\Domain\Payout\Models\ManualSettlement;
use App\Domain\Shared\Exceptions\ApiException;
use App\Filament\Resources\Settlements\Pages\ListSettlements;
use App\Filament\Support\StepUp;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SettlementResource extends Resource
{
    protected static ?string $model = ManualSettlement::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Opérations';

    protected static ?string $navigationLabel = 'Règlements manuels';

    protected static ?string $modelLabel = 'règlement';

    protected static ?string $pluralModelLabel = 'règlements manuels';

    protected static ?string $slug = 'reglements';

    protected static ?int $navigationSort = 3;

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
     * @return Builder<ManualSettlement>
     */
    public static function getEloquentQuery(): Builder
    {
        return ManualSettlement::query()->with(['batch.beneficiary', 'recorder']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ManualSettlement::query()->where('status', ManualSettlementStatus::PendingApproval)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('batch.reference')->label('Versement')->weight('bold')->description(fn (ManualSettlement $record): string => $record->batch->beneficiary->full_name),
                TextColumn::make('amount')->label('Montant')->alignEnd()->weight('bold')->state(fn (ManualSettlement $record): string => Format::xof($record->amount)),
                TextColumn::make('method')->label('Moyen')->visibleFrom('lg'),
                TextColumn::make('reference')->label('Référence')->visibleFrom('lg'),
                TextColumn::make('recorder.first_name')->label('Saisi par')->state(fn (ManualSettlement $record): string => $record->recorder->full_name)->visibleFrom('xl'),
                TextColumn::make('paid_at')->label('Réglé le')->state(fn (ManualSettlement $record): string => Format::dateTime($record->paid_at))->visibleFrom('xl'),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (ManualSettlementStatus $state): string => Labels::label('settlement', $state->value))
                    ->color(fn (ManualSettlementStatus $state): string => match ($state) {
                        ManualSettlementStatus::Applied => 'success',
                        ManualSettlementStatus::Rejected => 'danger',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->default(ManualSettlementStatus::PendingApproval->value)
                    ->options(collect(ManualSettlementStatus::cases())->mapWithKeys(fn (ManualSettlementStatus $status): array => [$status->value => Labels::label('settlement', $status->value)])->all()),
            ])
            ->recordActions([
                self::decision('approve', 'Approuver', 'success', [], fn (ManualSettlementService $service, User $checker, ManualSettlement $settlement, array $data) => $service->approve($checker, $settlement), 'Règlement approuvé : le versement est mis à jour.'),
                self::decision('reject', 'Rejeter', 'danger', [
                    Textarea::make('reason')->label('Motif (5 caractères minimum)')->required()->minLength(5)->maxLength(1000),
                ], fn (ManualSettlementService $service, User $checker, ManualSettlement $settlement, array $data) => $service->reject($checker, $settlement, (string) $data['reason']), 'Règlement rejeté.'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Aucun règlement')
            ->emptyStateDescription('Aucun règlement manuel ne correspond à ce filtre.');
    }

    /**
     * @param  array<int, mixed>  $fields
     * @param  Closure(ManualSettlementService, User, ManualSettlement, array<string, mixed>): mixed  $run
     */
    private static function decision(string $name, string $label, string $color, array $fields, Closure $run, string $success): Action
    {
        $action = Action::make($name)
            ->label($label)
            ->color($color)
            ->modalHeading($label)
            ->modalSubmitActionLabel($label)
            ->schema([...$fields, ...StepUp::fields()])
            ->visible(fn (ManualSettlement $record): bool => $record->status === ManualSettlementStatus::PendingApproval && auth()->user()?->can('payouts.approve_manual') === true)
            ->mountUsing(fn () => StepUp::issue())
            ->action(function (Action $action, ManualSettlement $record, array $data) use ($run, $success): void {
                StepUp::confirm($action, $data);

                $checker = auth()->user();
                abort_unless($checker instanceof User, 401);

                try {
                    $run(app(ManualSettlementService::class), $checker, $record, $data);
                } catch (ApiException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title($success)->success()->send();
            });

        if ($fields === []) {
            $action->requiresConfirmation()->modalDescription('Confirmez-vous cette décision ?');
        }

        return $action;
    }

    public static function getPages(): array
    {
        return ['index' => ListSettlements::route('/')];
    }
}
