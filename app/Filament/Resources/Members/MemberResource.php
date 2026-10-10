<?php

namespace App\Filament\Resources\Members;

use App\Domain\Access\AccountSearch;
use App\Domain\Backoffice\Support\Format;
use App\Domain\Backoffice\Support\Labels;
use App\Domain\Identity\Enums\PersonType;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MemberResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|UnitEnum|null $navigationGroup = 'Gestion';

    protected static ?string $navigationLabel = 'Membres';

    protected static ?string $modelLabel = 'membre';

    protected static ?string $pluralModelLabel = 'membres';

    protected static ?string $slug = 'membres';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('members.view') === true;
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
     * Members only, with their Pernum, membership status, active pack families and YEM balance.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return app(AccountSearch::class)->builder(UserKind::Member, []);
    }

    public static function displayName(User $member): string
    {
        return $member->organization->legal_name ?? $member->full_name;
    }

    public static function pernumOf(User $member): string
    {
        $identity = $member->pernumIdentities->where('status', '!=', 'revoked')->first();

        return $identity === null ? '' : (string) $identity->pernum;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Membre')
                    ->state(fn (User $record): string => self::displayName($record))
                    ->weight('bold')
                    ->description(fn (User $record): string => trim(self::pernumOf($record).' · '.$record->email, ' ·'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereIn(
                        'users.id',
                        app(AccountSearch::class)->builder(UserKind::Member, ['search' => $search])->reorder()->select('users.id'),
                    )),
                TextColumn::make('person_type')
                    ->label('Type')
                    ->state(fn (User $record): string => Labels::personType($record->person_type?->value))
                    ->visibleFrom('lg'),
                TextColumn::make('active_families')
                    ->label('Packs')
                    ->state(fn (User $record): string => collect(explode(',', (string) $record->getAttribute('active_families')))->filter()->map(fn (string $family): string => Labels::family($family))->implode(', '))
                    ->placeholder('Aucun')
                    ->visibleFrom('xl'),
                TextColumn::make('yem_balance')
                    ->label('Solde')
                    ->alignEnd()
                    ->state(fn (User $record): string => Format::yem((string) $record->getAttribute('yem_balance'))),
                TextColumn::make('status')
                    ->label('Compte')
                    ->badge()
                    ->formatStateUsing(fn (UserStatus $state): string => Labels::label('account', $state->value))
                    ->color(fn (UserStatus $state): string => match ($state) {
                        UserStatus::Active => 'success',
                        UserStatus::Suspended, UserStatus::Closed => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('created_at')->label('Inscrit le')->date('d M Y')->visibleFrom('xl'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut du compte')
                    ->options(collect(UserStatus::cases())->mapWithKeys(fn (UserStatus $status): array => [$status->value => Labels::label('account', $status->value)])->all()),
                SelectFilter::make('person_type')
                    ->label('Type de membre')
                    ->options([PersonType::Natural->value => 'Personne physique', PersonType::Legal->value => 'Personne morale']),
                SelectFilter::make('family')
                    ->label('Famille de pack')
                    ->options(Labels::families())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $inner, string $family): Builder => $inner->whereExists(
                        fn ($packs) => $packs->from('user_packs')->whereColumn('user_packs.user_id', 'users.id')->where('status', 'active')->where('family', $family),
                    ))),
            ])
            ->recordUrl(fn (User $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Aucun membre')
            ->emptyStateDescription('Aucun membre ne correspond à cette recherche.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'view' => ViewMember::route('/{record}'),
        ];
    }
}
