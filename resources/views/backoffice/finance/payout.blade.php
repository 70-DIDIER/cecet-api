@extends('backoffice.layouts.app')

@use('App\Domain\Backoffice\Support\Format')
@use('App\Domain\Backoffice\Support\Labels')

@section('title', 'Versement '.$payout->reference)

@section('content')
    @php
        $beneficiary = $payout->beneficiary->organization->legal_name ?? $payout->beneficiary->full_name;
        $hasFailed = $payout->items->contains(fn ($item) => $item->status->value === 'failed');
        $hasPending = $payout->items->contains(fn ($item) => $item->status->value === 'pending');
        $inProgress = $payout->items->contains(fn ($item) => $item->status->value === 'processing');
        $settleable = $payout->remaining_amount > 0;
    @endphp

    <div class="page-header">
        <div>
            <h1 class="page-title">Versement {{ $payout->reference }}</h1>
            <p class="page-subtitle"><a href="{{ route('backoffice.finance.payouts') }}"><i class="bi bi-arrow-left"></i> Retour aux versements</a></p>
        </div>
        <span class="status-badge {{ Labels::badge('payout', $payout->status->value) }}">{{ Labels::label('payout', $payout->status->value) }}</span>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Détail</h2></div>
                <dl class="key-figures mb-0">
                    <div><dt>Bénéficiaire</dt><dd><a href="{{ route('backoffice.members.show', $payout->beneficiary) }}">{{ $beneficiary }}</a></dd></div>
                    <div><dt>Demande d'origine</dt><dd>{{ $payout->payable->reference ?? '—' }}</dd></div>
                    <div><dt>Moyen de versement</dt><dd>{{ $payout->method }}</dd></div>
                    <div><dt>Numéro / compte</dt><dd>{{ $payout->destination['phone'] ?? ($payout->destination['account'] ?? '—') }}</dd></div>
                    <div><dt>Total dû</dt><dd>{{ Format::xof($payout->total_amount) }}</dd></div>
                    <div><dt>Déjà versé</dt><dd>{{ Format::xof($payout->paid_amount) }}</dd></div>
                    <div><dt>Reste à verser</dt><dd>{{ Format::xof($payout->remaining_amount) }}</dd></div>
                    <div><dt>Créé le</dt><dd>{{ Format::dateTime($payout->created_at) }}</dd></div>
                    @if ($payout->blocked_reason)
                        <div><dt>Blocage</dt><dd>{{ $payout->blocked_reason }}</dd></div>
                    @endif
                </dl>
            </div>

            <div class="card mt-4">
                <div class="card-header"><h2 class="card-title">Actions</h2></div>

                @if ($payout->status->value === 'awaiting_dispatch' && $hasPending)
                    @can('payouts.retry')
                        <form method="POST" action="{{ route('backoffice.finance.payout.dispatch', $payout) }}" class="mb-3">
                            @csrf
                            <p class="small text-muted-green">{{ $autoEnabled ? 'Les versements automatiques sont activés : ce dossier part normalement seul.' : 'Les versements automatiques sont désactivés : envoyez-les vous-même.' }}</p>
                            <button class="btn btn-dark w-100" type="submit"><i class="bi bi-send"></i> Envoyer les virements Mobile Money</button>
                        </form>
                    @endcan
                @endif

                @if ($inProgress)
                    <form method="POST" action="{{ route('backoffice.finance.payout.refresh', $payout) }}" class="mb-3">
                        @csrf
                        <button class="btn btn-outline-dark w-100" type="submit"><i class="bi bi-arrow-repeat"></i> Actualiser auprès du prestataire</button>
                    </form>
                @endif

                @if ($hasFailed)
                    @can('payouts.retry')
                        <form method="POST" action="{{ route('backoffice.finance.payout.retry', $payout) }}" class="mb-3">
                            @csrf
                            <label class="form-label" for="retry-reason">Relancer les virements en échec : motif</label>
                            <textarea class="form-control mb-2" id="retry-reason" name="reason" rows="2" minlength="5" maxlength="1000" required></textarea>
                            <button class="btn btn-warning w-100" type="submit"><i class="bi bi-arrow-clockwise"></i> Relancer les échecs</button>
                            <div class="form-text">Un virement déjà versé n'est jamais renvoyé.</div>
                        </form>
                    @endcan
                @endif

                @if (! $hasPending && ! $hasFailed && ! $inProgress && ! $settleable)
                    <p class="text-muted-green mb-0">Ce versement est complet : aucune action n'est nécessaire.</p>
                @endif
            </div>

            @if ($settleable)
                @can('payouts.settle_manual')
                    <div class="card mt-4">
                        <div class="card-header"><h2 class="card-title">Règlement manuel</h2></div>
                        <p class="small text-muted-green">Virement bancaire ou espèces couvrant le reste à verser. Au-delà du seuil configuré, une autre personne devra l'approuver avant qu'il soit pris en compte.</p>
                        <form method="POST" action="{{ route('backoffice.finance.payout.settle', $payout) }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label" for="method">Moyen</label>
                                <select class="form-select" id="method" name="method" required>
                                    @foreach ($manualMethods as $code => $label)
                                        <option value="{{ $code }}" @selected(old('method') === $code)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="amount">Montant versé (FCFA)</label>
                                <input class="form-control" id="amount" name="amount" type="number" min="1" max="{{ $payout->remaining_amount }}" step="1" required value="{{ old('amount', $payout->remaining_amount) }}">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="reference">Référence (n° d'opération, reçu…)</label>
                                <input class="form-control" id="reference" name="reference" type="text" maxlength="120" required value="{{ old('reference') }}">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="paid_at">Date du versement</label>
                                <input class="form-control" id="paid_at" name="paid_at" type="datetime-local" required value="{{ old('paid_at', now()->format('Y-m-d\TH:i')) }}" max="{{ now()->format('Y-m-d\TH:i') }}">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="note">Note (facultatif)</label>
                                <textarea class="form-control" id="note" name="note" rows="2" maxlength="2000">{{ old('note') }}</textarea>
                            </div>
                            <button class="btn btn-success w-100" type="submit">Enregistrer le règlement</button>
                        </form>
                    </div>
                @endcan
            @endif
        </div>

        <div class="col-lg-7">
            <div class="card table-card">
                <div class="card-header p-3 mb-0"><h2 class="card-title">Virements</h2></div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>#</th><th>Mode</th><th class="text-end">Montant</th><th>Statut</th><th>Prestataire</th><th>Essais</th><th>Dernière erreur</th></tr></thead>
                        <tbody>
                        @forelse ($payout->items as $item)
                            <tr>
                                <td>{{ $item->sequence }}</td>
                                <td>{{ $item->kind->value === 'manual' ? 'Manuel' : 'Mobile Money' }}</td>
                                <td class="amount-cell">{{ Format::xof($item->amount) }}</td>
                                <td><span class="status-badge {{ Labels::badge('payout_item', $item->status->value) }}">{{ Labels::label('payout_item', $item->status->value) }}</span></td>
                                <td class="small">{{ $item->provider ?? '—' }}<div class="text-muted-green">{{ $item->provider_reference }}</div></td>
                                <td>{{ $item->attempts }}</td>
                                <td class="small">{{ $item->last_error ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><div class="empty-state">Aucun virement automatique : règlement manuel requis.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card table-card mt-4">
                <div class="card-header p-3 mb-0"><h2 class="card-title">Règlements manuels</h2></div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Date</th><th>Moyen</th><th>Référence</th><th class="text-end">Montant</th><th>Statut</th></tr></thead>
                        <tbody>
                        @forelse ($payout->manualSettlements as $settlement)
                            <tr>
                                <td class="text-nowrap">{{ Format::dateTime($settlement->paid_at) }}</td>
                                <td>{{ $settlement->method }}</td>
                                <td class="small">{{ $settlement->reference }}@if ($settlement->decision_reason)<div class="text-muted-green">{{ $settlement->decision_reason }}</div>@endif</td>
                                <td class="amount-cell">{{ Format::xof($settlement->amount) }}</td>
                                <td><span class="status-badge {{ Labels::badge('settlement', $settlement->status->value) }}">{{ Labels::label('settlement', $settlement->status->value) }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty-state">Aucun règlement manuel.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
