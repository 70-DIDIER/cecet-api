@extends('backoffice.layouts.app')

@use('App\Domain\Backoffice\Support\Format')

@section('title', 'Réception '.$exchange->reference)

@section('content')
    @php
        $open = $exchange->status->isOpen();
        $statusLabels = [
            'awaiting_transfer' => 'En attente du transfert', 'declared_sent' => 'Envoi déclaré', 'yem_received' => 'YEM reçus', 'completed' => 'Terminée',
            'canceled' => 'Annulée', 'expired' => 'Expirée', 'rejected' => 'Rejetée', 'disputed' => 'En litige',
        ];
        $name = $exchange->user->organization->legal_name ?? $exchange->user->full_name;
        $kindLabel = $exchange->kind->value === 'merchant_redemption' ? 'Rééchange marchand' : 'Vente de YEM (Vendeur)';
    @endphp

    <div class="page-header">
        <div>
            <h1 class="page-title">{{ $kindLabel }} {{ $exchange->reference }}</h1>
            <p class="page-subtitle"><a href="{{ route('backoffice.validations') }}"><i class="bi bi-arrow-left"></i> Retour à la file</a></p>
        </div>
        <span class="status-badge {{ $open ? 'is-warning' : ($exchange->status->isYemConfirmed() ? 'is-success' : 'is-muted') }}">{{ $statusLabels[$exchange->status->value] ?? $exchange->status->value }}</span>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Détail de la demande</h2></div>
                <dl class="key-figures mb-0">
                    <div><dt>Membre</dt><dd>{{ $name }}</dd></div>
                    <div><dt>Numéro de membre</dt><dd>{{ $exchange->user->member_number }}</dd></div>
                    <div><dt>Pernum source (à vérifier)</dt><dd>{{ $exchange->source_pernum }}</dd></div>
                    <div><dt>Pernum de la CECT (destinataire)</dt><dd>{{ $exchange->cect_pernum }}</dd></div>
                    <div><dt>Pack utilisé</dt><dd>{{ $exchange->userPack->plan->code }}</dd></div>
                    <div><dt>Quantité demandée</dt><dd>{{ Format::yem($exchange->quantity_requested, 6) }}</dd></div>
                    <div><dt>Cours figé à la demande</dt><dd>{{ Format::xof($exchange->rate_xof_per_yem) }} / YEM</dd></div>
                    <div><dt>Contre-valeur estimée</dt><dd>{{ Format::xof(\App\Support\YemQuantity::toXof($exchange->quantity_requested, $exchange->rate_xof_per_yem)) }}</dd></div>
                    <div><dt>Référence donnée par le membre</dt><dd>{{ $exchange->member_transfer_reference ?? '—' }}</dd></div>
                    <div><dt>Envoi déclaré le</dt><dd>{{ Format::dateTime($exchange->declared_at) }}</dd></div>
                    @if ($exchange->quantity_received !== null)
                        <div><dt>Quantité reçue (validée)</dt><dd>{{ Format::yem($exchange->quantity_received, 6) }}</dd></div>
                        <div><dt>Montant dû</dt><dd>{{ Format::xof($exchange->amount_due_xof) }}</dd></div>
                    @endif
                </dl>
            </div>

            <div class="card mt-4">
                <div class="card-header"><h2 class="card-title">Historique des décisions</h2></div>
                @forelse ($exchange->validationEvents as $event)
                    <div class="transaction-item">
                        <div class="transaction-icon bg-forest-light text-lime"><i class="bi bi-clipboard-check"></i></div>
                        <div class="transaction-info">
                            <div class="transaction-name">{{ ['confirmed' => 'Réception confirmée', 'rejected' => 'Rejetée', 'disputed' => 'Mise en litige'][$event->action->value] ?? $event->action->value }}
                                — {{ $event->actor->full_name ?? 'Système' }}</div>
                            <div class="transaction-date">{{ Format::dateTime($event->created_at) }}
                                @if ($event->quantity_received) · {{ Format::yem($event->quantity_received, 6) }} @endif
                                @if ($event->external_reference) · réf. {{ $event->external_reference }} @endif
                                @if ($event->comment) · {{ $event->comment }} @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-muted-green mb-0">Aucune décision pour le moment.</p>
                @endforelse
            </div>
        </div>

        <div class="col-lg-5">
            @if ($open)
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Confirmer la réception</h2></div>
                    <p class="small text-muted-green">Confirmez uniquement ce que vous avez <strong>vu arriver</strong> sur le Pernum de la CECT. La déclaration du membre n'est pas une preuve. Le montant dû est calculé par la plateforme.</p>
                    <form method="POST" action="{{ route('backoffice.validations.exchange.confirm', $exchange) }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label" for="quantity_received">Quantité réellement reçue (YEM)</label>
                            <input class="form-control" id="quantity_received" name="quantity_received" type="text" inputmode="decimal" required value="{{ old('quantity_received', $exchange->quantity_requested) }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="external_reference">Référence du transfert dans YEMPay {{ $requireReference ? '' : '(facultatif)' }}</label>
                            <input class="form-control" id="external_reference" name="external_reference" type="text" maxlength="120" {{ $requireReference ? 'required' : '' }} value="{{ old('external_reference') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="comment">Commentaire (facultatif)</label>
                            <textarea class="form-control" id="comment" name="comment" rows="2" maxlength="2000">{{ old('comment') }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-success w-100">Confirmer la réception</button>
                    </form>
                </div>

                <div class="card mt-4">
                    <div class="card-header"><h2 class="card-title">Rejeter ou mettre en litige</h2></div>
                    <form method="POST" action="{{ route('backoffice.validations.exchange.reject', $exchange) }}" class="mb-3">
                        @csrf
                        <label class="form-label" for="reject-reason">Transfert introuvable : motif (communiqué au membre)</label>
                        <textarea class="form-control mb-2" id="reject-reason" name="reason" rows="2" minlength="5" maxlength="2000" required></textarea>
                        <button type="submit" class="btn btn-outline-danger w-100">Rejeter et libérer la quantité</button>
                    </form>
                    @if ($exchange->status->value !== 'disputed')
                        <form method="POST" action="{{ route('backoffice.validations.exchange.dispute', $exchange) }}">
                            @csrf
                            <label class="form-label" for="dispute-comment">Données incohérentes : commentaire</label>
                            <textarea class="form-control mb-2" id="dispute-comment" name="comment" rows="2" minlength="5" maxlength="2000" required></textarea>
                            <button type="submit" class="btn btn-outline-warning w-100">Mettre en litige (la quantité reste réservée)</button>
                        </form>
                    @endif
                </div>
            @else
                <div class="card"><p class="mb-0">Cette demande est close : aucune décision n'est plus possible.</p></div>
            @endif
        </div>
    </div>
@endsection
