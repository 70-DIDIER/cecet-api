<?php

namespace App\Filament\Resources\Payouts\Pages;

use App\Domain\Backoffice\Support\Format;
use App\Domain\Backoffice\Support\Labels;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Payout\Actions\ManualSettlementService;
use App\Domain\Payout\Actions\PayoutOperations;
use App\Domain\Payout\Enums\PayoutBatchStatus;
use App\Domain\Payout\Enums\PayoutRoute;
use App\Domain\Payout\Models\ManualSettlement;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Payout\Models\PayoutItem;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Exceptions\ApiException;
use App\Filament\Resources\Payouts\PayoutResource;
use App\Filament\Support\StepUp;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class ViewPayout extends ViewRecord
{
    protected static string $resource = PayoutResource::class;

    private function payout(): PayoutBatch
    {
        $record = $this->getRecord();
        abort_unless($record instanceof PayoutBatch, 404);

        return $record;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Versement '.$this->payout()->reference;
    }

    public function getSubheading(): ?string
    {
        return $this->payout()->beneficiary->full_name;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Résumé')->columns(['default' => 1, 'md' => 3])->schema([
                TextEntry::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn (PayoutBatchStatus $state): string => Labels::label('payout', $state->value))
                    ->color(fn (PayoutBatchStatus $state): string => PayoutResource::statusColor($state)),
                TextEntry::make('route')->label('Mode')->state(fn (PayoutBatch $record): string => $record->route === PayoutRoute::MobileMoney ? 'Mobile Money' : 'Règlement manuel'),
                TextEntry::make('destination')->label('Destination')->state(fn (PayoutBatch $record): string => trim($record->method.' · '.($record->destination['phone'] ?? $record->destination['account_number'] ?? ''), ' ·')),
                TextEntry::make('total_amount')->label('Total')->state(fn (PayoutBatch $record): string => Format::xof($record->total_amount)),
                TextEntry::make('paid_amount')->label('Déjà versé')->state(fn (PayoutBatch $record): string => Format::xof($record->paid_amount)),
                TextEntry::make('remaining_amount')->label('Reste dû')->weight('bold')->state(fn (PayoutBatch $record): string => Format::xof($record->remaining_amount)),
                TextEntry::make('operation')->label('Opération')->state(function (PayoutBatch $record): string {
                    $payable = $record->payable;

                    return $payable instanceof ExchangeRequest ? $payable->reference.' · '.Format::yem($payable->quantity_received ?? $payable->quantity_requested) : '—';
                }),
                TextEntry::make('blocked_reason')->label('Blocage')->placeholder('—')->color('danger')->columnSpanFull(),
            ]),
            Section::make('Transferts')->schema([
                RepeatableEntry::make('transfers')->hiddenLabel()->contained(false)->placeholder('Aucun transfert.')->columns(6)
                    ->state(fn (PayoutBatch $record): array => $record->items()->orderBy('sequence')->get()->map(fn (PayoutItem $item): array => [
                        'sequence' => '#'.$item->sequence,
                        'amount' => Format::xof($item->amount),
                        'fee' => Format::xof($item->fee_amount),
                        'status' => Labels::label('payout_item', $item->status->value),
                        'reference' => (string) ($item->provider_reference ?? ''),
                        'error' => (string) ($item->last_error ?? ''),
                    ])->all())
                    ->schema([
                        TextEntry::make('sequence')->label('N°'),
                        TextEntry::make('amount')->label('Montant'),
                        TextEntry::make('fee')->label('Frais'),
                        TextEntry::make('status')->label('Statut'),
                        TextEntry::make('reference')->label('Référence prestataire')->placeholder('—'),
                        TextEntry::make('error')->label('Erreur')->placeholder('—')->color('danger'),
                    ]),
            ]),
            Section::make('Règlements manuels')->schema([
                RepeatableEntry::make('settlements')->hiddenLabel()->contained(false)->placeholder('Aucun règlement manuel.')->columns(5)
                    ->state(fn (PayoutBatch $record): array => $record->manualSettlements()->with('recorder')->orderByDesc('paid_at')->get()->map(fn (ManualSettlement $settlement): array => [
                        'amount' => Format::xof($settlement->amount),
                        'method' => $settlement->method,
                        'reference' => $settlement->reference,
                        'status' => Labels::label('settlement', $settlement->status->value),
                        'recorder' => $settlement->recorder->full_name,
                    ])->all())
                    ->schema([
                        TextEntry::make('amount')->label('Montant'),
                        TextEntry::make('method')->label('Moyen'),
                        TextEntry::make('reference')->label('Référence'),
                        TextEntry::make('status')->label('Statut'),
                        TextEntry::make('recorder')->label('Saisi par'),
                    ]),
            ]),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Actualiser')
                ->color('gray')
                ->visible(fn (): bool => $this->payout()->status === PayoutBatchStatus::Processing && auth()->user()?->can('payouts.view') === true)
                ->action(function (): void {
                    app(PayoutOperations::class)->refresh($this->payout());
                    Notification::make()->title('Statut actualisé auprès du prestataire.')->success()->send();
                    $this->refreshFormData(['status', 'paid_amount', 'remaining_amount']);
                }),

            $this->sensitive('dispatch', 'Envoyer les transferts', 'primary', 'payouts.retry', [PayoutBatchStatus::AwaitingDispatch], [], fn (User $actor, array $data) => app(PayoutOperations::class)->dispatch($actor, $this->payout()), 'Transferts envoyés.'),

            $this->sensitive('retry', 'Relancer les échecs', 'warning', 'payouts.retry', [PayoutBatchStatus::Failed, PayoutBatchStatus::ManualRequired], [
                Textarea::make('reason')->label('Motif (5 caractères minimum)')->required()->minLength(5)->maxLength(1000),
            ], fn (User $actor, array $data) => app(PayoutOperations::class)->retry($actor, $this->payout(), (string) $data['reason']), 'Transferts relancés.'),

            $this->sensitive('settle', 'Enregistrer un règlement manuel', 'gray', 'payouts.settle_manual', null, [
                Select::make('method')->label('Moyen utilisé')->required()->native(false)->options(
                    collect(app(SettingsService::class)->array('payment.methods'))->filter(fn (mixed $method): bool => is_array($method) && ($method['enabled'] ?? false) === true)->mapWithKeys(fn (array $method): array => [(string) $method['code'] => (string) $method['label']])->all(),
                ),
                TextInput::make('amount')->label('Montant réglé (FCFA)')->numeric()->required()->minValue(1)->default(fn (): int => $this->payout()->remaining_amount),
                TextInput::make('reference')->label('Référence du règlement')->required()->maxLength(120),
                DateTimePicker::make('paid_at')->label('Date du règlement')->required()->default(now())->native(false),
                Textarea::make('note')->label('Note (facultatif)')->maxLength(2000),
            ], fn (User $actor, array $data) => app(ManualSettlementService::class)->record($actor, $this->payout(), (string) $data['method'], (string) $data['reference'], (int) $data['amount'], now()->parse($data['paid_at']), $data['note'] ?? null), 'Règlement enregistré : il doit être approuvé par une autre personne.'),
        ];
    }

    /**
     * @param  array<int, PayoutBatchStatus>|null  $statuses  statuses in which the action is offered (null: any with an amount left)
     * @param  array<int, mixed>  $fields
     * @param  Closure(User, array<string, mixed>): mixed  $run
     */
    private function sensitive(string $name, string $label, string $color, string $permission, ?array $statuses, array $fields, Closure $run, string $success): Action
    {
        $action = Action::make($name)
            ->label($label)
            ->color($color)
            ->modalHeading($label)
            ->modalSubmitActionLabel($label)
            ->schema([...$fields, ...StepUp::fields()])
            ->visible(fn (): bool => auth()->user()?->can($permission) === true
                && ($statuses === null ? $this->payout()->remaining_amount > 0 : in_array($this->payout()->status, $statuses, true)))
            ->mountUsing(fn () => StepUp::issue())
            ->action(function (Action $action, array $data) use ($run, $success): void {
                StepUp::confirm($action, $data);

                $actor = auth()->user();
                abort_unless($actor instanceof User, 401);

                try {
                    $run($actor, $data);
                } catch (ApiException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title($success)->success()->send();
                $this->refreshFormData(['status', 'paid_amount', 'remaining_amount']);
            });

        if ($fields === []) {
            $action->requiresConfirmation()->modalDescription('Envoyer les transferts restants au bénéficiaire ?');
        }

        return $action;
    }
}
