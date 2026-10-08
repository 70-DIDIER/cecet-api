@extends('backoffice.layouts.app')

@use('App\Domain\Backoffice\Support\Format')

@section('title', 'Validations')

@section('content')
    <div class="page-header">
        <div>
            <h1 class="page-title">Validations</h1>
            <p class="page-subtitle">Paiements hors prestataire, YEM déclarés envoyés et paiements marchands à arbitrer.</p>
        </div>
        <div class="btn-group" role="group" aria-label="Affichage">
            <a href="{{ route('backoffice.validations') }}" class="btn btn-sm {{ $history ? 'btn-outline-secondary' : 'btn-dark' }}">À traiter</a>
            <a href="{{ route('backoffice.validations', ['statut' => 'historique']) }}" class="btn btn-sm {{ $history ? 'btn-dark' : 'btn-outline-secondary' }}">Historique</a>
        </div>
    </div>

    @if (! $history && ($canDecidePayments || $canDecideYem))
        @if ($stepUpActive)
            <div class="alert alert-success py-2 small" role="status">
                <i class="bi bi-shield-check"></i> Identité confirmée jusqu'à {{ $stepUpUntil->format('H:i') }} : vous pouvez décider.
            </div>
        @else
            <div class="alert alert-warning py-2 small d-flex justify-content-between align-items-center flex-wrap gap-2" role="alert">
                <span><i class="bi bi-shield-lock"></i> Pour valider ou rejeter, confirmez d'abord votre identité avec un code envoyé par e-mail.</span>
                <a class="btn btn-sm btn-dark" href="{{ route('backoffice.step-up', ['return' => request()->getRequestUri()]) }}">Confirmer mon identité</a>
            </div>
        @endif
    @endif

    <div class="card table-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Objet</th>
                        <th>Membre</th>
                        <th class="text-end">Montant / quantité</th>
                        <th>Référence déclarée</th>
                        <th>Statut</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($items as $item)
                    @php
                        $statusClass = ['pending' => 'is-warning', 'disputed' => 'is-warning', 'validated' => 'is-success', 'rejected' => 'is-danger'][$item['status']] ?? 'is-muted';
                        $statusLabel = ['pending' => 'À traiter', 'disputed' => 'Litige', 'validated' => 'Validé', 'rejected' => 'Rejeté'][$item['status']] ?? $item['status'];
                        $open = in_array($item['status'], ['pending', 'disputed'], true);
                        $modal = 'm'.substr($item['id'], -10);
                    @endphp
                    <tr>
                        <td class="text-nowrap">{{ Format::dateTime(\Carbon\CarbonImmutable::parse($item['date'])) }}</td>
                        <td>
                            <div class="fw-semibold">{{ $item['label'] }}</div>
                            @if ($item['method'])
                                <div class="small text-muted-green">{{ $methodLabels[$item['method']] ?? $item['method'] }}</div>
                            @endif
                        </td>
                        <td>
                            <div>{{ $item['member']['name'] }}</div>
                            <div class="small text-muted-green">{{ $item['member']['pernum'] ?? $item['member']['member_number'] }}</div>
                        </td>
                        <td class="amount-cell">
                            @if ($item['amount_xof'] !== null && $item['kind'] !== 'yem_receipt')
                                {{ Format::xof($item['amount_xof']) }}
                            @else
                                {{ Format::yem($item['quantity'], 6) }}
                                @if ($item['amount_xof'] !== null)
                                    <div class="small text-muted-green">≈ {{ Format::xof($item['amount_xof']) }}</div>
                                @endif
                            @endif
                        </td>
                        <td class="small">{{ $item['declared_reference'] ?? '—' }}</td>
                        <td><span class="status-badge {{ $statusClass }}">{{ $statusLabel }}</span></td>
                        <td class="text-end text-nowrap">
                            @if ($open && $item['kind'] === 'payment' && $canDecidePayments)
                                <button class="btn btn-sm btn-success" type="button" data-bs-toggle="modal" data-bs-target="#approve-{{ $modal }}">Valider</button>
                                <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#reject-{{ $modal }}">Rejeter</button>
                            @elseif ($open && $item['kind'] === 'yem_receipt')
                                <a class="btn btn-sm btn-dark" href="{{ route('backoffice.validations.exchange', $item['id']) }}">Examiner</a>
                            @elseif ($open && $item['kind'] === 'merchant_payment' && $canDecideYem)
                                <button class="btn btn-sm btn-success" type="button" data-bs-toggle="modal" data-bs-target="#approve-{{ $modal }}">Confirmer</button>
                                <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#reject-{{ $modal }}">Rejeter</button>
                            @else
                                <span class="text-muted-green">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox fs-3 d-block mb-2"></i>{{ $history ? 'Aucune décision enregistrée.' : 'Rien n\'attend de décision pour le moment.' }}</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Fenêtres de décision --}}
    @foreach ($items as $item)
        @continue(! in_array($item['status'], ['pending', 'disputed'], true))
        @php($modal = 'm'.substr($item['id'], -10))

        @if ($item['kind'] === 'payment' && $canDecidePayments)
            <div class="modal fade" id="approve-{{ $modal }}" tabindex="-1" aria-labelledby="approve-{{ $modal }}-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <form class="modal-content" method="POST" action="{{ route('backoffice.validations.payments.approve', $item['id']) }}">
                        @csrf
                        <div class="modal-header">
                            <h2 class="modal-title fs-5" id="approve-{{ $modal }}-title">Valider le paiement</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-3"><strong>{{ $item['label'] }}</strong> — {{ $item['member']['name'] }}</p>
                            <div class="mb-3">
                                <label class="form-label" for="amount-{{ $modal }}">Montant effectivement reçu (FCFA)</label>
                                <input class="form-control" id="amount-{{ $modal }}" name="received_amount" type="number" min="1" step="1" required value="{{ $item['amount_xof'] }}">
                                <div class="form-text">Doit être égal au montant dû ({{ Format::xof($item['amount_xof']) }}) ; sinon la validation est refusée.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="ref-{{ $modal }}">Référence du reçu ou du dépôt</label>
                                <input class="form-control" id="ref-{{ $modal }}" name="reference" type="text" maxlength="120" required value="{{ $item['declared_reference'] }}">
                            </div>
                            <div>
                                <label class="form-label" for="comment-{{ $modal }}">Commentaire (facultatif)</label>
                                <textarea class="form-control" id="comment-{{ $modal }}" name="comment" rows="2" maxlength="2000"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-success">Valider le paiement</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        @if (($item['kind'] === 'payment' && $canDecidePayments) || ($item['kind'] === 'merchant_payment' && $canDecideYem))
            @if ($item['kind'] === 'merchant_payment')
                <div class="modal fade" id="approve-{{ $modal }}" tabindex="-1" aria-labelledby="approve-{{ $modal }}-title" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <form class="modal-content" method="POST" action="{{ route('backoffice.validations.merchant.confirm', $item['id']) }}">
                            @csrf
                            <div class="modal-header">
                                <h2 class="modal-title fs-5" id="approve-{{ $modal }}-title">Confirmer le paiement marchand</h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                            </div>
                            <div class="modal-body">
                                <p><strong>{{ $item['label'] }}</strong></p>
                                <p class="mb-0">Payeur : {{ $item['member']['name'] }} — {{ Format::yem($item['quantity'], 6) }}.</p>
                                <p class="small text-muted-green mt-2 mb-0">À confirmer uniquement si le transfert est visible sur le Pernum du marchand. Le quota du payeur est alors consommé et le solde du marchand crédité.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                                <button type="submit" class="btn btn-success">Confirmer</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif

            <div class="modal fade" id="reject-{{ $modal }}" tabindex="-1" aria-labelledby="reject-{{ $modal }}-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <form class="modal-content" method="POST"
                          action="{{ $item['kind'] === 'payment' ? route('backoffice.validations.payments.reject', $item['id']) : route('backoffice.validations.merchant.reject', $item['id']) }}">
                        @csrf
                        <div class="modal-header">
                            <h2 class="modal-title fs-5" id="reject-{{ $modal }}-title">Rejeter</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                        <div class="modal-body">
                            <p><strong>{{ $item['label'] }}</strong> — {{ $item['member']['name'] }}</p>
                            <label class="form-label" for="reason-{{ $modal }}">Motif (communiqué au membre)</label>
                            <textarea class="form-control" id="reason-{{ $modal }}" name="reason" rows="3" minlength="5" maxlength="2000" required></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-danger">Rejeter</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endforeach
@endsection
