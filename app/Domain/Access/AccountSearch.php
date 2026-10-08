<?php

namespace App\Domain\Access;

use App\Domain\Identity\Enums\UserKind;
use App\Domain\Reporting\ReportingQueries;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The search over accounts shared by the API and the back-office screens: name, e-mail, member number, company name
 * and Pernum; filters on status, person type, role (staff) and active pack family (members). For members each row
 * also carries its Pernum, membership status, active pack families and YEM balance.
 */
class AccountSearch
{
    public function __construct(private readonly ReportingQueries $reporting) {}

    /**
     * @param  array{status?: string|null, person_type?: string|null, role?: string|null, search?: string|null, family?: string|null}  $filters
     * @return Builder<User>
     */
    public function builder(UserKind $kind, array $filters): Builder
    {
        return User::query()
            ->where('kind', $kind)
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['person_type'] ?? null, fn ($query, string $type) => $query->where('person_type', $type))
            ->when($kind === UserKind::Staff ? ($filters['role'] ?? null) : null, fn ($query, string $role) => $query->role($role))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $term = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                $query->where(fn ($inner) => $inner
                    ->whereRaw('lower(first_name) like ?', [$term])
                    ->orWhereRaw('lower(last_name) like ?', [$term])
                    ->orWhere('email', 'like', $term)
                    ->orWhere('member_number', 'like', mb_strtoupper($term))
                    ->orWhereHas('organization', fn ($organization) => $organization->whereRaw('lower(legal_name) like ?', [$term]))
                    ->orWhereHas('pernumIdentities', fn ($pernum) => $pernum->where('pernum', 'like', $term)->where('status', '!=', 'revoked')));
            })
            ->when($kind === UserKind::Member ? ($filters['family'] ?? null) : null, fn ($query, string $family) => $query->whereExists(
                fn ($packs) => $packs->from('user_packs')->whereColumn('user_packs.user_id', 'users.id')->where('status', 'active')->where('family', $family),
            ))
            ->when($kind === UserKind::Member, fn ($query) => $query
                ->select('users.*')
                ->selectSub($this->reporting->membershipStatusOf('users.id'), 'membership_status')
                ->selectSub($this->reporting->activeFamiliesOf('users.id'), 'active_families')
                ->selectSub($this->reporting->balanceOf('users.id'), 'yem_balance')
                ->with('pernumIdentities'))
            ->with(['organization', 'roles'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
