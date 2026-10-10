<?php

namespace App\Filament\Pages;

use App\Domain\Backoffice\Support\Format;
use App\Domain\Exchange\Actions\ValidationDesk;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Merchant\Actions\MerchantPaymentService;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Domain\Payment\Actions\ManualPaymentDesk;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\ValidationQueue;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Exceptions\ApiException;
use App\Filament\Support\StepUp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class Validations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';

    protected static string|\UnitEnum|null $navigationGroup = 'Opérations';

    protected static ?string $navigationLabel = 'Validations';

    protected static ?string $title = 'Validations';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('payments.validate_manual') || $user->can('yem_validations.view'));
    }

    public function getSubheading(): ?string
    {
        return 'Paiements reçus hors prestataire, réceptions de YEM sur le Pernum CECT et paiements marchands à arbitrer.';
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $count = app(ValidationQueue::class)->items($user->can('payments.validate_manual'), $user->can('yem_validations.view'), false)->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters): array {
                $user = auth()->user();
                $history = ($filters['view']['value'] ?? 'pending') === 'history';

                return app(ValidationQueue::class)
                    ->items($user?->can('payments.validate_manual') === true, $user?->can('yem_validations.view') === true, $history)
                    ->mapWithKeys(fn (array $item): array => [$item['kind'].':'.$item['id'] => $item])
                    ->all();
            })
            ->paginated(false)
            ->filters([
                SelectFilter::make('view')
                    ->label('Affichage')
                    ->options(['pending' => 'En attente', 'history' => 'Historique'])
                    ->default('pending')
                    ->selectablePlaceholder(false),
            ])
            ->emptyStateHeading('Rien à traiter')
            ->emptyStateDescription('Aucune demande ne correspond à cet affichage.')
            ->columns([
                TextColumn::make('member.name')
                    ->label('Membre')
                    ->weight('bold')
                    ->description(fn (array $record): string => (string) ($record['member']['pernum'] ?? $record['member']['member_number'] ?? '')),
                TextColumn::make('label')->label('Demande')->wrap(),
                TextColumn::make('amount')
                    ->label('Montant')
                    ->alignEnd()
                    ->weight('bold')
                    ->state(fn (array $record): string => $record['amount_xof'] !== null ? Format::xof((int) $record['amount_xof']) : Format::yem($record['quantity'])),
                TextColumn::make('method')->label('Moyen')->visibleFrom('2xl')->placeholder('—')->formatStateUsing(fn (?string $state): string => match ($state) {
                    'TMONEY' => 'T-Money',
                    'FLOOZ' => 'Flooz',
                    'CASH' => 'Espèces',
                    'BANK_TRANSFER' => 'Virement',
                    default => (string) $state,
                }),
                TextColumn::make('declared_reference')->label('Référence déclarée')->visibleFrom('xl')->placeholder('—'),
                TextColumn::make('date')->label('Date')->visibleFrom('xl')->formatStateUsing(fn (string $state): string => CarbonImmutable::parse($state)->translatedFormat('d M Y, H:i')),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'En attente',
                        'validated' => 'Validé',
                        'rejected' => 'Rejeté',
                        'disputed' => 'Litige',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'validated' => 'success',
                        default => 'danger',
                    }),
            ])
            ->recordActions([
                $this->decision('approvePayment', 'Valider', 'payment', 'payments.validate_manual', 'success', [
                    TextInput::make('received_amount')->label('Montant reçu (FCFA)')->numeric()->required()->minValue(1),
                    TextInput::make('reference')->label('Référence du dépôt ou du virement')->required()->maxLength(120),
                    Textarea::make('comment')->label('Commentaire (facultatif)')->maxLength(2000),
                ], fn (User $user, array $record, array $data) => app(ManualPaymentDesk::class)->approve($user, PaymentAttempt::query()->findOrFail((string) $record['id']), (int) $data['received_amount'], (string) $data['reference'], $data['comment'] ?? null), 'Paiement validé : le membre en est informé.', fn (array $record): array => ['received_amount' => $record['amount_xof'], 'reference' => $record['declared_reference']]),

                $this->decision('rejectPayment', 'Rejeter', 'payment', 'payments.validate_manual', 'danger', [
                    Textarea::make('reason')->label('Motif (5 caractères minimum)')->required()->minLength(5)->maxLength(2000),
                ], fn (User $user, array $record, array $data) => app(ManualPaymentDesk::class)->reject($user, PaymentAttempt::query()->findOrFail((string) $record['id']), (string) $data['reason']), 'Paiement rejeté : le membre en est informé.'),

                $this->decision('confirmYem', 'Confirmer', 'yem_receipt', 'yem_validations.confirm', 'success', [
                    TextInput::make('quantity_received')->label('Quantité reçue (YEM)')->required()->regex('/^\d{1,22}(\.\d{1,6})?$/'),
                    TextInput::make('external_reference')->label('Référence de la transaction YEM')->required(fn (): bool => app(SettingsService::class)->boolean('validation.require_external_reference'))->maxLength(120),
                    Textarea::make('comment')->label('Commentaire (facultatif)')->maxLength(2000),
                ], fn (User $user, array $record, array $data) => app(ValidationDesk::class)->confirm($user, ExchangeRequest::query()->findOrFail((string) $record['id']), (string) $data['quantity_received'], $data['external_reference'] ?? null, $data['comment'] ?? null), 'Réception confirmée : la contre-valeur est désormais due.', fn (array $record): array => ['quantity_received' => $record['quantity'], 'external_reference' => $record['declared_reference']]),

                $this->decision('disputeYem', 'Litige', 'yem_receipt', 'yem_validations.dispute', 'warning', [
                    Textarea::make('comment')->label('Motif du litige (5 caractères minimum)')->required()->minLength(5)->maxLength(2000),
                ], fn (User $user, array $record, array $data) => app(ValidationDesk::class)->dispute($user, ExchangeRequest::query()->findOrFail((string) $record['id']), (string) $data['comment']), 'Demande placée en litige : la quantité reste réservée.'),

                $this->decision('rejectYem', 'Rejeter', 'yem_receipt', 'yem_validations.confirm', 'danger', [
                    Textarea::make('reason')->label('Motif (5 caractères minimum)')->required()->minLength(5)->maxLength(2000),
                ], fn (User $user, array $record, array $data) => app(ValidationDesk::class)->reject($user, ExchangeRequest::query()->findOrFail((string) $record['id']), (string) $data['reason']), 'Demande rejetée : la quantité réservée est libérée.'),

                $this->decision('confirmMerchant', 'Confirmer', 'merchant_payment', 'yem_validations.confirm', 'success', [], fn (User $user, array $record, array $data) => app(MerchantPaymentService::class)->confirmByValidator($user, MerchantPayment::query()->findOrFail((string) $record['id'])), 'Paiement marchand confirmé.'),

                $this->decision('rejectMerchant', 'Rejeter', 'merchant_payment', 'yem_validations.confirm', 'danger', [
                    Textarea::make('reason')->label('Motif (5 caractères minimum)')->required()->minLength(5)->maxLength(2000),
                ], fn (User $user, array $record, array $data) => app(MerchantPaymentService::class)->rejectByValidator($user, MerchantPayment::query()->findOrFail((string) $record['id']), (string) $data['reason']), 'Paiement marchand rejeté : la quantité est rendue au payeur.'),
            ]);
    }

    /**
     * A decision on one line of the queue: opens a form, asks for the e-mail confirmation code when needed, then calls
     * the business action and shows its refusal, if any, instead of an error page.
     *
     * @param  array<int, mixed>  $fields
     * @param  Closure(User, array<string, mixed>, array<string, mixed>): mixed  $run
     * @param  (Closure(array<string, mixed>): array<string, mixed>)|null  $defaults
     */
    private function decision(string $name, string $label, string $kind, string $permission, string $color, array $fields, Closure $run, string $success, ?Closure $defaults = null): Action
    {
        $action = Action::make($name)
            ->label($label)
            ->color($color)
            ->modalHeading($label)
            ->modalSubmitActionLabel($label)
            ->schema([...$fields, ...StepUp::fields()])
            ->visible(fn (array $record): bool => $record['kind'] === $kind
                && in_array($record['status'], ['pending', 'disputed'], true)
                && auth()->user()?->can($permission) === true)
            ->mountUsing(function (Schema $schema, array $record) use ($defaults): void {
                StepUp::issue();

                if ($defaults !== null) {
                    $schema->fill($defaults($record));
                }
            })
            ->action(function (Action $action, array $record, array $data) use ($run, $success): void {
                StepUp::confirm($action, $data);

                $user = auth()->user();
                abort_unless($user instanceof User, 401);

                try {
                    $run($user, $record, $data);
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
}
