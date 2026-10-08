@extends('backoffice.layouts.app')

@use('App\Domain\Backoffice\Support\Format')
@use('App\Domain\Backoffice\Support\Labels')

@section('title', 'Membres')

@section('content')
    <div class="page-header">
        <div>
            <h1 class="page-title">Membres</h1>
            <p class="page-subtitle">{{ Format::number($members->total()) }} membre(s) correspondant aux filtres.</p>
        </div>
        @if (Route::has('backoffice.exports.show'))
            @can('reports.export')
                <a class="btn btn-sm btn-outline-dark" href="{{ route('backoffice.exports.show', 'members') }}"><i class="bi bi-download"></i> Exporter (CSV)</a>
            @endcan
        @endif
    </div>

    <form method="GET" action="{{ route('backoffice.members') }}" class="card filters-bar mb-4">
        <div class="flex-grow-1">
            <label class="form-label small mb-1" for="search">Recherche</label>
            <input class="form-control" id="search" name="search" type="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" placeholder="Nom, e-mail, Pernum, n° de membre, raison sociale">
        </div>
        <div>
            <label class="form-label small mb-1" for="status">Statut du compte</label>
            <select class="form-select" id="status" name="status">
                <option value="">Tous</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ Labels::label('account', $status->value) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label small mb-1" for="person_type">Type</label>
            <select class="form-select" id="person_type" name="person_type">
                <option value="">Tous</option>
                <option value="natural" @selected(($filters['person_type'] ?? '') === 'natural')>Personne physique</option>
                <option value="legal" @selected(($filters['person_type'] ?? '') === 'legal')>Personne morale</option>
            </select>
        </div>
        <div>
            <label class="form-label small mb-1" for="family">Pack actif</label>
            <select class="form-select" id="family" name="family">
                <option value="">Tous</option>
                @foreach (Labels::families() as $code => $label)
                    <option value="{{ $code }}" @selected(($filters['family'] ?? '') === $code)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-dark" type="submit"><i class="bi bi-search"></i> Filtrer</button>
            <a class="btn btn-light" href="{{ route('backoffice.members') }}">Réinitialiser</a>
        </div>
    </form>

    <div class="card table-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Membre</th>
                        <th>Pernum</th>
                        <th>Contact</th>
                        <th>Adhésion</th>
                        <th>Packs actifs</th>
                        <th class="text-end">Solde YEM</th>
                        <th>Compte</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($members as $member)
                    @php($pernum = $member->pernumIdentities->first(fn ($identity) => $identity->status->value !== 'revoked'))
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $member->organization->legal_name ?? $member->full_name }}</div>
                            <div class="small text-muted-green">{{ $member->member_number }} · {{ Labels::personType($member->person_type?->value) }}</div>
                        </td>
                        <td>{{ $pernum?->pernum ?? '—' }}</td>
                        <td>
                            <div class="small">{{ $member->phone ?? '—' }}</div>
                            <div class="small text-muted-green">{{ $member->email }}</div>
                        </td>
                        <td>
                            @if ($member->membership_status)
                                <span class="status-badge {{ Labels::badge('membership', $member->membership_status) }}">{{ Labels::label('membership', $member->membership_status) }}</span>
                            @else
                                <span class="text-muted-green">—</span>
                            @endif
                        </td>
                        <td>
                            @forelse (array_filter(explode(',', (string) $member->active_families)) as $family)
                                <span class="status-badge is-muted">{{ Labels::family($family) }}</span>
                            @empty
                                <span class="text-muted-green">Aucun</span>
                            @endforelse
                        </td>
                        <td class="amount-cell">{{ Format::yem((string) $member->yem_balance) }}</td>
                        <td><span class="status-badge {{ Labels::badge('account', $member->status->value) }}">{{ Labels::label('account', $member->status->value) }}</span></td>
                        <td class="text-end"><a class="btn btn-sm btn-dark" href="{{ route('backoffice.members.show', $member) }}">Voir</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty-state"><i class="bi bi-people fs-3 d-block mb-2"></i>Aucun membre ne correspond à ces filtres.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($members->hasPages())
        <div class="mt-3">{{ $members->links('backoffice.partials.pagination') }}</div>
    @endif
@endsection
