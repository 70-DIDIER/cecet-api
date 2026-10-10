<?php

namespace App\Filament\Resources\Members\Pages;

use App\Domain\Access\AccountManager;
use App\Domain\Backoffice\Support\Format;
use App\Domain\Backoffice\Support\Labels;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Shared\Exceptions\ApiException;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Support\MemberOverview;
use App\Filament\Support\StepUp;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class ViewMember extends ViewRecord
{
    protected static string $resource = MemberResource::class;

    public function getTitle(): string|Htmlable
    {
        return MemberResource::displayName($this->member());
    }

    private function member(): User
    {
        $record = $this->getRecord();
        abort_unless($record instanceof User, 404);

        return $record;
    }

    public function getSubheading(): ?string
    {
        $member = $this->member();

        return trim(MemberResource::pernumOf($member).' · '.$member->email, ' ·');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Identité')->columns(['default' => 1, 'md' => 3])->schema([
                TextEntry::make('kind')->label('Type de compte')->state(fn (User $record): string => Labels::personType($record->person_type?->value)),
                TextEntry::make('status')->label('Compte')->badge()
                    ->formatStateUsing(fn (UserStatus $state): string => Labels::label('account', $state->value))
                    ->color(fn (UserStatus $state): string => match ($state) {
                        UserStatus::Active => 'success',
                        UserStatus::Suspended, UserStatus::Closed => 'danger',
                        default => 'warning',
                    }),
                TextEntry::make('membership_status')->label('Adhésion')->badge()->state(fn (User $record): string => Labels::label('membership', (string) $record->getAttribute('membership_status')))->placeholder('—'),
                TextEntry::make('member_number')->label('Numéro de membre')->placeholder('—'),
                TextEntry::make('email')->label('E-mail'),
                TextEntry::make('phone')->label('Téléphone')->placeholder('—'),
                TextEntry::make('city')->label('Ville')->placeholder('—'),
                TextEntry::make('yem_balance')->label('Solde YEM')->state(fn (User $record): string => Format::yem((string) $record->getAttribute('yem_balance'))),
                TextEntry::make('created_at')->label('Inscrit le')->state(fn (User $record): string => Format::date($record->created_at)),
                TextEntry::make('last_login_at')->label('Dernière connexion')->state(fn (User $record): string => $record->last_login_at === null ? '—' : Format::dateTime($record->last_login_at)),
            ]),
            $this->listing('Packs', fn (User $record): array => MemberOverview::packs($record), [
                'name' => 'Pack', 'family' => 'Famille', 'status' => 'Statut', 'balance' => 'Solde', 'since' => 'Actif depuis',
            ]),
            $this->listing('Paiements', fn (User $record): array => MemberOverview::payments($record), [
                'reference' => 'Référence', 'method' => 'Moyen', 'amount' => 'Montant', 'status' => 'Statut', 'date' => 'Date',
            ]),
            $this->listing('Ventes de YEM', fn (User $record): array => MemberOverview::exchanges($record), [
                'reference' => 'Référence', 'quantity' => 'Quantité', 'status' => 'Statut', 'date' => 'Date',
            ]),
            $this->listing('Paiements marchands', fn (User $record): array => MemberOverview::merchantPayments($record), [
                'reference' => 'Référence', 'role' => 'Rôle', 'quantity' => 'Quantité', 'status' => 'Statut', 'date' => 'Date',
            ]),
            $this->listing('Journal', fn (User $record): array => MemberOverview::events($record), [
                'action' => 'Événement', 'reason' => 'Motif', 'date' => 'Date',
            ]),
        ]);
    }

    /**
     * @param  Closure(User): array<int, array<string, string>>  $rows
     * @param  array<string, string>  $columns
     */
    private function listing(string $title, Closure $rows, array $columns): Section
    {
        return Section::make($title)->collapsible()->schema([
            RepeatableEntry::make(str($title)->slug('_')->toString())
                ->hiddenLabel()
                ->state($rows)
                ->contained(false)
                ->placeholder('Aucun élément.')
                ->columns(count($columns))
                ->schema(collect($columns)->map(fn (string $label, string $key): TextEntry => TextEntry::make($key)->label($label)->placeholder('—'))->values()->all()),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->accountAction('suspend', 'Suspendre le compte', 'danger', UserStatus::Active, fn (AccountManager $manager, User $actor, User $member, string $reason) => $manager->suspend($actor, $member, $reason), 'Compte suspendu : ses sessions sont fermées.'),
            $this->accountAction('reactivate', 'Réactiver le compte', 'success', UserStatus::Suspended, fn (AccountManager $manager, User $actor, User $member, string $reason) => $manager->reactivate($actor, $member, $reason), 'Compte réactivé.'),
        ];
    }

    /**
     * @param  Closure(AccountManager, User, User, string): mixed  $run
     */
    private function accountAction(string $name, string $label, string $color, UserStatus $when, Closure $run, string $success): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->modalHeading($label)
            ->modalSubmitActionLabel($label)
            ->schema([
                Textarea::make('reason')->label('Motif (5 caractères minimum)')->required()->minLength(5)->maxLength(1000),
                ...StepUp::fields(),
            ])
            ->visible(fn (): bool => $this->member()->status === $when && auth()->user()?->can('members.manage') === true)
            ->mountUsing(fn () => StepUp::issue())
            ->action(function (Action $action, array $data) use ($run, $success): void {
                StepUp::confirm($action, $data);

                $actor = auth()->user();
                abort_unless($actor instanceof User, 401);

                try {
                    $run(app(AccountManager::class), $actor, $this->member(), (string) $data['reason']);
                } catch (ApiException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title($success)->success()->send();
                $this->refreshFormData(['status']);
            });
    }
}
