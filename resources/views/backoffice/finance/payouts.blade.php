@extends('backoffice.layouts.app')

@use('App\Domain\Backoffice\Support\Format')
@use('App\Domain\Backoffice\Support\Labels')

@section('title', 'Versements')

@section('content')
    <div class="page-header">
        <div>
            <h1 class="page-title">Versements</h1>
            <p class="page-subtitle">Contre-valeurs en FCFA dues aux membres et aux marchands après validation de leurs YEM.</p>
        </div>
        @can('payouts.settle_manual')
            <a class="btn btn-sm btn-outline-dark" href="{{ route('backoffice.finance.settlements') }}">
                Règlements manuels à approuver
                @if ($pendingSettlements > 0)<span class="badge text-bg-warning">{{ $pendingSettlements }}</span>@endif
            </a>
        @endcan
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4"><div class="card card-stat"><span class="stat-label">Reste dû</span><div class="stat-value">{{ Format::xof($owed) }}</div></div></div>
        <div class="col-md-4"><div class="card card-stat"><span class="stat-label">Dossiers à traiter (échec ou manuel)</span><div class="stat-value">{{ Format::number($needAction) }}</div></div></div>
        <div class="col-md-4"><div class="card card-stat"><span class="stat-label">Règlements en attente d'approbation</span><div class="stat-value">{{ Format::number($pendingSettlements) }}</div></div></div>
    </div>

    <form method="GET" action="{{ route('backoffice.finance.payouts') }}" class="card filters-bar mb-4">
        <div class="flex-grow-1">
            <label class="form-label small mb-1" for="search">Référence</label>
            <input class="form-control" id="search" name="search" type="search" maxlength="60" value="{{ $filters['search'] ?? '' }}" placeholder="PO-…">
        </div>
        <div>
            <label class="form-label small mb-1" for="status">Statut</label>
            <select class="form-select" id="status" name="status">
                <option value="">Tous</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ Labels::label('payout', $status->value) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label small mb-1" for="route">Mode</label>
            <select class="form-select" id="route" name="route">
                <option value="">Tous</option>
                <option value="mobile_money" @selected(($filters['route'] ?? '') === 'mobile_money')>Mobile Money</option>
                <option value="manual" @selected(($filters['route'] ?? '') === 'manual')>Manuel (banque, espèces)</option>
            </select>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-dark" type="submit"><i class="bi bi-search"></i> Filtrer</button>
            <a class="btn btn-light" href="{{ route('backoffice.finance.payouts') }}">Réinitialiser</a>
        </div>
    </form>

    <div class="card table-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr><th>Référence</th><th>Bénéficiaire</th><th>Origine</th><th>Moyen</th><th class="text-end">Total</th><th class="text-end">Versé</th><th class="text-end">Reste</th><th>Statut</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($batches as $batch)
                    <tr>
                        <td class="fw-semibold">{{ $batch->reference }}</td>
                        <td>{{ $batch->beneficiary->organization->legal_name ?? $batch->beneficiary->full_name }}</td>
                        <td class="small">{{ $batch->payable->reference ?? '—' }}</td>
                        <td>{{ $batch->method }}<div class="small text-muted-green">{{ $batch->route->value === 'manual' ? 'Manuel' : 'Mobile Money' }}</div></td>
                        <td class="amount-cell">{{ Format::xof($batch->total_amount) }}</td>
                        <td class="amount-cell">{{ Format::xof($batch->paid_amount) }}</td>
                        <td class="amount-cell">{{ Format::xof($batch->remaining_amount) }}</td>
                        <td><span class="status-badge {{ Labels::badge('payout', $batch->status->value) }}">{{ Labels::label('payout', $batch->status->value) }}</span></td>
                        <td class="text-end"><a class="btn btn-sm btn-dark" href="{{ route('backoffice.finance.payout', $batch) }}">Ouvrir</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9"><div class="empty-state"><i class="bi bi-cash-coin fs-3 d-block mb-2"></i>Aucun versement ne correspond à ces filtres.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($batches->hasPages())
        <div class="mt-3">{{ $batches->links('backoffice.partials.pagination') }}</div>
    @endif
@endsection
