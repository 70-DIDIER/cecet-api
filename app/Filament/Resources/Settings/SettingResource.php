<?php

namespace App\Filament\Resources\Settings;

use App\Domain\Settings\Enums\SettingEditLevel;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Exceptions\ApiException;
use App\Filament\Resources\Settings\Pages\ListSettings;
use App\Filament\Support\StepUp;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SettingResource extends Resource
{
    protected static ?string $model = Setting::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|UnitEnum|null $navigationGroup = 'Gestion';

    protected static ?string $navigationLabel = 'Configuration';

    protected static ?string $modelLabel = 'paramètre';

    protected static ?string $pluralModelLabel = 'paramètres';

    protected static ?string $slug = 'configuration';

    protected static ?int $navigationSort = 4;

    private const GROUPS = [
        'membership' => 'Adhésion',
        'organization' => 'Organisation',
        'payment' => 'Paiement',
        'payout' => 'Versements',
        'rates' => 'Cours et taux',
        'yem' => 'YEM',
        'auth' => 'Authentification',
        'otp' => 'Codes de confirmation',
        'validation' => 'Validation',
        'ops' => 'Exploitation',
        'reports' => 'Rapports',
        'packs' => 'Packs',
    ];

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('settings.view') === true;
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
     * @return Builder<Setting>
     */
    public static function getEloquentQuery(): Builder
    {
        return Setting::query()->orderBy('group')->orderBy('key');
    }

    public static function groupLabel(string $group): string
    {
        return self::GROUPS[$group] ?? ucfirst($group);
    }

    public static function canChange(Setting $setting): bool
    {
        if ($setting->is_sensitive) {
            return false;
        }

        return auth()->user()?->can($setting->edit_level === SettingEditLevel::Superadmin ? 'settings.update_critical' : 'settings.update') === true;
    }

    public static function displayValue(Setting $setting): string
    {
        if ($setting->is_sensitive) {
            return '••••••••';
        }

        $value = app(SettingsService::class)->get($setting->key);

        return match (true) {
            $value === null => 'Non défini',
            is_bool($value) => $value ? 'Oui' : 'Non',
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label('Paramètre')->searchable()->weight('bold')->fontFamily('mono')->description(fn (Setting $record): string => $record->description)->wrap(),
                TextColumn::make('group')->label('Catégorie')->badge()->color('gray')->formatStateUsing(fn (string $state): string => self::groupLabel($state))->visibleFrom('lg'),
                TextColumn::make('value')->label('Valeur')->state(fn (Setting $record): string => self::displayValue($record))->limit(60)->tooltip(fn (Setting $record): string => self::displayValue($record)),
                TextColumn::make('edit_level')->label('Niveau')->badge()->visibleFrom('xl')
                    ->formatStateUsing(fn (SettingEditLevel $state): string => $state === SettingEditLevel::Superadmin ? 'Critique' : 'Courant')
                    ->color(fn (SettingEditLevel $state): string => $state === SettingEditLevel::Superadmin ? 'warning' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('group')
                    ->label('Catégorie')
                    ->options(fn (): array => Setting::query()->distinct()->orderBy('group')->pluck('group')->mapWithKeys(fn (string $group): array => [$group => self::groupLabel($group)])->all()),
                SelectFilter::make('edit_level')
                    ->label('Niveau')
                    ->options([SettingEditLevel::Admin->value => 'Courant', SettingEditLevel::Superadmin->value => 'Critique']),
            ])
            ->recordActions([
                self::editAction(),
                self::historyAction(),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Aucun paramètre');
    }

    private static function editAction(): Action
    {
        return Action::make('edit')
            ->label('Modifier')
            ->icon('heroicon-o-pencil-square')
            ->modalHeading(fn (Setting $record): string => 'Modifier '.$record->key)
            ->modalDescription(fn (Setting $record): string => $record->description)
            ->modalSubmitActionLabel('Enregistrer')
            ->visible(fn (Setting $record): bool => self::canChange($record))
            ->schema(fn (Setting $record): array => [
                self::valueField($record),
                Textarea::make('reason')->label('Motif (5 caractères minimum)')->required()->minLength(5)->maxLength(1000),
                DateTimePicker::make('effective_from')->label('Entrée en vigueur (facultatif : immédiate par défaut)')->native(false)->minDate(now())->seconds(false),
                ...StepUp::fields(),
            ])
            ->mountUsing(function (Schema $schema, Setting $record): void {
                StepUp::issue();
                $schema->fill(['value' => self::initialValue($record)]);
            })
            ->action(function (Action $action, Setting $record, array $data): void {
                StepUp::confirm($action, $data);

                $actor = auth()->user();
                abort_unless($actor instanceof User && self::canChange($record), 403);

                try {
                    app(SettingsService::class)->set(
                        $record->key,
                        self::castValue($record, $data['value'] ?? null),
                        (string) $data['reason'],
                        filled($data['effective_from'] ?? null) ? CarbonImmutable::parse($data['effective_from']) : null,
                        $actor,
                    );
                } catch (ApiException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Paramètre enregistré : une nouvelle version est créée.')->success()->send();
            });
    }

    private static function historyAction(): Action
    {
        return Action::make('history')
            ->label('Historique')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->modalHeading(fn (Setting $record): string => 'Historique de '.$record->key)
            ->modalContent(fn (Setting $record) => view('filament.settings.history', [
                'versions' => app(SettingsService::class)->versions($record->key),
                'sensitive' => $record->is_sensitive,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer');
    }

    private static function valueField(Setting $setting): Component
    {
        $field = match ($setting->type) {
            SettingType::Boolean => Toggle::make('value'),
            SettingType::Integer => TextInput::make('value')->numeric()->integer()->required(),
            SettingType::Decimal => TextInput::make('value')->numeric()->required(),
            SettingType::Json => Textarea::make('value')->rows(10)->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace'])->required()->rule(self::jsonRule()),
            default => TextInput::make('value')->required(),
        };

        return $field->label('Nouvelle valeur');
    }

    private static function jsonRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            json_decode((string) $value);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $fail('Le contenu n\'est pas un JSON valide.');
            }
        };
    }

    private static function initialValue(Setting $setting): mixed
    {
        $value = app(SettingsService::class)->get($setting->key);

        return $setting->type === SettingType::Json ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value;
    }

    private static function castValue(Setting $setting, mixed $value): mixed
    {
        return match ($setting->type) {
            SettingType::Integer => (int) $value,
            SettingType::Boolean => (bool) $value,
            SettingType::Json => json_decode((string) $value, true),
            default => $value,
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListSettings::route('/')];
    }
}
