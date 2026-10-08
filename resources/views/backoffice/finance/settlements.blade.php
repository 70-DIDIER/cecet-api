@extends('backoffice.layouts.app')

@use('App\Domain\Backoffice\Support\Format')

@section('title', 'Règlements manuels')

@section('content')
    <div class="page-header">
        <div>
            <h1 class="page-title">Règlements manuels à approuver</h1>
            <p class="page-subtitle"><a href="{{ route('backoffice.finance.payouts') }}"><i class="bi bi-arrow-left"></i> Retour aux versements</a> — celui qui a enregistré un règlement ne peut pas l'approuver.</p>
        </div>
    </div>

    <div class="card table-card">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Versement</th><th>Bénéficiaire</th><th>Enregistré par</th><th>Moyen / référence</th><th class="text-end">Montant</th><th class="text-end">Décision</th></tr></thead>
                <tbody>
                @forelse ($pending as $settlement)
                    @php($own = $settlement->recorded_by === auth()->id())
                    <tr>
                        <td><a href="{{ route('backoffice.finance.payout', $settlement->batch) }}">{{ $settlement->batch->reference }}</a></td>
                        <td>{{ $settlement->batch->beneficiary->organization->legal_name ?? $settlement->batch->beneficiary->full_name }}</td>
                        <td>{{ $settlement->recorder->full_name }}<div class="small text-muted-green">{{ Format::dateTime($settlement->paid_at) }}</div></td>
                        <td>{{ $settlement->method }}<div class="small text-muted-green">{{ $settlement->reference }}</div></td>
                        <td class="amount-cell">{{ Format::xof($settlement->amount) }}</td>
                        <td class="text-end text-nowrap">
                            @can('payouts.approve_manual')
                                @if ($own)
                                    <span class="small text-muted-green">Enregistré par vous</span>
                                @else
                                    <form class="d-inline" method="POST" action="{{ route('backoffice.finance.settlement.approve', $settlement) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-success" type="submit">Approuver</button>
                                    </form>
                                    <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#reject-{{ $settlement->id }}">Rejeter</button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><i class="bi bi-check-circle fs-3 d-block mb-2"></i>Aucun règlement en attente.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @foreach ($pending as $settlement)
        <div class="modal fade" id="reject-{{ $settlement->id }}" tabindex="-1" aria-labelledby="reject-{{ $settlement->id }}-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="{{ route('backoffice.finance.settlement.reject', $settlement) }}">
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="reject-{{ $settlement->id }}-title">Rejeter le règlement</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body">
                        <p>{{ $settlement->batch->reference }} — {{ Format::xof($settlement->amount) }} ({{ $settlement->method }}, {{ $settlement->reference }})</p>
                        <label class="form-label" for="reason-{{ $settlement->id }}">Motif</label>
                        <textarea class="form-control" id="reason-{{ $settlement->id }}" name="reason" rows="3" minlength="5" maxlength="1000" required></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-danger">Rejeter</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach
@endsection
