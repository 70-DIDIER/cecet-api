@extends('backoffice.layouts.app')

@use('App\Domain\Backoffice\Support\Format')
@use('App\Domain\Backoffice\Support\Labels')

@section('title', $member->organization->legal_name ?? $member->full_name)

@section('content')
    @php
        $pernum = $member->pernumIdentities->first(fn ($identity) => $identity->status->value !== 'revoked');
        $name = $member->organization->legal_name ?? $member->full_name;
    @endphp

    <div class="page-header">
        <div>
            <h1 class="page-title">{{ $name }}</h1>
            <p class="page-subtitle"><a href="{{ route('backoffice.members') }}"><i class="bi bi-arrow-left"></i> Retour aux membres</a></p>
        </div>
        <span class="status-badge {{ Labels::badge('account', $member->status->value) }}">{{ Labels::label('account', $member->status->value) }}</span>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Identité</h2></div>
                <dl class="key-figures mb-0">
                    <div><dt>Numéro de membre</dt><dd>{{ $member->member_number }}</dd></div>
                    <div><dt>Type</dt><dd>{{ Labels::personType($member->person_type?->value) }}</dd></div>
                    @if ($member->organization)
                        <div><dt>Statut juridique</dt><dd>{{ $member->organization->legal_form }}</dd></div>
                        <div><dt>RCCM</dt><dd>{{ $member->organization->rccm ?? '—' }}</dd></div>
                        <div><dt>NIF</dt><dd>{{ $member->organization->nif ?? '—' }}</dd></div>
                    @endif
                    <div><dt>{{ $member->organization ? 'Représentant' : 'Nom' }}</dt><dd>{{ $member->full_name }}</dd></div>
                    <div><dt>Pernum</dt><dd>{{ $pernum?->pernum ?? '—' }}</dd></div>
                    <div><dt>E-mail</dt><dd>{{ $member->email }}</dd></div>
                    <div><dt>Téléphone</dt><dd>{{ $member->phone ?? '—' }}</dd></div>
                    <div><dt>Ville</dt><dd>{{ $member->city ?? '—' }}</dd></div>
                    <div><dt>Inscrit le</dt><dd>{{ Format::dateTime($member->created_at) }}</dd></div>
                    <div><dt>Dernière connexion</dt><dd>{{ Format::dateTime($member->last_login_at) }}</dd></div>
                    <div><dt>Engagement signé</dt><dd>{{ $member->commitment_accepted_at ? Format::date($member->commitment_accepted_at).' à '.($member->signed_place ?? '—') : 'Non' }}</dd></div>
                </dl>
            </div>

            <div class="card mt-4">
                <div class="card-header"><h2 class="card-title">Adhésion</h2></div>
                @if ($membership)
                    <dl class="key-figures mb-0">
                        <div><dt>Statut</dt><dd><span class="status-badge {{ Labels::badge('membership', $membership->status->value) }}">{{ Labels::label('membership', $membership->status->value) }}</span></dd></div>
                        <div><dt>Montant</dt><dd>{{ Format::xof($membership->fee_amount) }}</dd></div>
                        <div><dt>Payée le</dt><dd>{{ Format::dateTime($membership->paid_at) }}</dd></div>
                        <div><dt>Activée le</dt><dd>{{ Format::dateTime($membership->activated_at) }}</dd></div>
                    </dl>
                @else
                    <p class="text-muted-green mb-0">Aucun paiement d'adhésion n'a encore été démarré.</p>
                @endif
            </div>

            @can('members.manage')
                <div class="card mt-4">
                    <div class="card-header"><h2 class="card-title">{{ $member->status->value === 'suspended' ? 'Réactiver le compte' : 'Suspendre le compte' }}</h2></div>
                    @if ($member->status->value === 'suspended')
                        <p class="small text-muted-green">Suspendu le {{ Format::dateTime($member->suspended_at) }}. La réactivation rétablit l'état d'avant la suspension.</p>
                    @else
                        <p class="small text-muted-green">La suspension ferme toutes les sessions du membre et l'empêche de se connecter. Les opérations déjà engagées restent traitées.</p>
                    @endif
                    <form method="POST" action="{{ route($member->status->value === 'suspended' ? 'backoffice.members.reactivate' : 'backoffice.members.suspend', $member) }}">
                        @csrf
                        <label class="form-label" for="reason">Motif (conservé dans le journal d'audit)</label>
                        <textarea class="form-control mb-2" id="reason" name="reason" rows="2" minlength="5" maxlength="1000" required></textarea>
                        <button type="submit" class="btn {{ $member->status->value === 'suspended' ? 'btn-success' : 'btn-outline-danger' }} w-100">
                            {{ $member->status->value === 'suspended' ? 'Réactiver' : 'Suspendre' }}
                        </button>
                    </form>
                </div>
            @endcan
        </div>

        <div class="col-lg-7">
            <div class="card table-card">
                <div class="card-header p-3 mb-0"><h2 class="card-title">Packs</h2></div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Pack</th><th>Statut</th><th class="text-end">Prix payé</th><th class="text-end">Disponible</th><th class="text-end">Réservé</th><th>Expire</th></tr></thead>
                        <tbody>
                        @forelse ($packs as $pack)
                            <tr>
                                <td><div class="fw-semibold">{{ $pack->plan->code }}</div><div class="small text-muted-green">{{ Labels::family($pack->family->value) }} · v{{ $pack->version->version }}</div></td>
                                <td><span class="status-badge {{ Labels::badge('pack', $pack->status->value) }}">{{ Labels::label('pack', $pack->status->value) }}</span></td>
                                <td class="amount-cell">{{ Format::xof($pack->price_xof) }}</td>
                                <td class="amount-cell">
                                    {{ Format::yem($pack->account?->available ?? '0') }}
                                    @if ($merchantAccounts->has($pack->id))
                                        <div class="small text-muted-green">Encaissements : {{ Format::yem($merchantAccounts[$pack->id]->eligible) }}</div>
                                    @endif
                                </td>
                                <td class="amount-cell">{{ Format::yem($pack->account?->reserved ?? '0') }}</td>
                                <td class="small">{{ Format::date($pack->expires_at) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><div class="empty-state">Aucun pack.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card table-card mt-4">
                <div class="card-header p-3 mb-0"><h2 class="card-title">Derniers paiements</h2></div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Date</th><th>Objet</th><th>Moyen</th><th class="text-end">Montant</th><th>Statut</th></tr></thead>
                        <tbody>
                        @forelse ($payments as $attempt)
                            <tr>
                                <td class="text-nowrap">{{ Format::dateTime($attempt->created_at) }}</td>
                                <td>{{ ['membership' => 'Adhésion', 'pack_subscription' => 'Pack', 'pack_topup' => 'Rechargement Perso'][$attempt->intent->purpose->value] ?? $attempt->intent->purpose->value }}</td>
                                <td>{{ $attempt->method ?? $attempt->provider }}</td>
                                <td class="amount-cell">{{ Format::xof($attempt->amount) }}</td>
                                <td><span class="status-badge {{ Labels::badge('payment', $attempt->status->value) }}">{{ Labels::label('payment', $attempt->status->value) }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty-state">Aucun paiement.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card table-card mt-4">
                <div class="card-header p-3 mb-0"><h2 class="card-title">Ventes et rééchanges de YEM</h2></div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Référence</th><th>Date</th><th class="text-end">Quantité</th><th class="text-end">Montant dû</th><th>Statut</th></tr></thead>
                        <tbody>
                        @forelse ($exchanges as $exchange)
                            <tr>
                                <td>@can('yem_validations.view')<a href="{{ route('backoffice.validations.exchange', $exchange) }}">{{ $exchange->reference }}</a>@else{{ $exchange->reference }}@endcan</td>
                                <td class="text-nowrap">{{ Format::dateTime($exchange->created_at) }}</td>
                                <td class="amount-cell">{{ Format::yem($exchange->quantity_received ?? $exchange->quantity_requested, 6) }}</td>
                                <td class="amount-cell">{{ $exchange->amount_due_xof !== null ? Format::xof($exchange->amount_due_xof) : '—' }}</td>
                                <td><span class="status-badge {{ Labels::badge('exchange', $exchange->status->value) }}">{{ Labels::label('exchange', $exchange->status->value) }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty-state">Aucune demande.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($merchantPayments->isNotEmpty())
                <div class="card table-card mt-4">
                    <div class="card-header p-3 mb-0"><h2 class="card-title">Paiements marchands</h2></div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>Référence</th><th>Date</th><th>Rôle</th><th>Contrepartie</th><th class="text-end">Quantité</th><th>Statut</th></tr></thead>
                            <tbody>
                            @foreach ($merchantPayments as $payment)
                                @php($asPayer = $payment->payer_id === $member->id)
                                <tr>
                                    <td>{{ $payment->reference }}</td>
                                    <td class="text-nowrap">{{ Format::dateTime($payment->created_at) }}</td>
                                    <td>{{ $asPayer ? 'Payeur' : 'Marchand' }}</td>
                                    <td>{{ $asPayer ? ($payment->merchant->full_name) : $payment->payer->full_name }}</td>
                                    <td class="amount-cell">{{ Format::yem($payment->quantity, 6) }}</td>
                                    <td><span class="status-badge {{ Labels::badge('merchant_payment', $payment->status->value) }}">{{ Labels::label('merchant_payment', $payment->status->value) }}</span></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @can('audit.view')
                <div class="card mt-4">
                    <div class="card-header"><h2 class="card-title">Dernières actions sur ce compte</h2></div>
                    @forelse ($events as $event)
                        <div class="transaction-item">
                            <div class="transaction-icon bg-forest-light text-lime"><i class="bi bi-journal-text"></i></div>
                            <div class="transaction-info">
                                <div class="transaction-name">{{ $event->action }}</div>
                                <div class="transaction-date">{{ Format::dateTime($event->occurred_at) }}@if ($event->reason) · {{ $event->reason }}@endif</div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted-green mb-0">Aucune action enregistrée.</p>
                    @endforelse
                </div>
            @endcan
        </div>
    </div>
@endsection
