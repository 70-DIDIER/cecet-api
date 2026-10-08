@extends('backoffice.layouts.app')

@use('App\Domain\Backoffice\Support\Format')

@section('title', 'Tableau de bord')

@section('content')
    <div class="page-header">
        <div>
            <h1 class="page-title">Tableau de bord</h1>
            <p class="page-subtitle">
                @if ($summary)
                    Activité du {{ Format::date($from) }} au {{ Format::date($lastDay) }}.
                @else
                    Bonjour {{ $currentUser->first_name }}, voici ce qui vous attend.
                @endif
            </p>
        </div>
        @if ($summary)
            <form method="GET" action="{{ route('backoffice.dashboard') }}" id="period-form">
                <input type="hidden" name="from" id="period-from" value="{{ $from->format('Y-m-d') }}">
                <input type="hidden" name="to" id="period-to" value="{{ $lastDay->format('Y-m-d') }}">
                <input type="text" id="period-input" class="visually-hidden" tabindex="-1" aria-hidden="true"
                       data-from="{{ $from->format('Y-m-d') }}" data-to="{{ $lastDay->format('Y-m-d') }}">
                <button class="btn-date-picker" type="button" id="date-picker-trigger" aria-label="Changer la période">
                    <i class="bi bi-calendar4-event"></i>
                    <span>{{ Format::date($from) }} – {{ Format::date($lastDay) }}</span>
                    <i class="bi bi-chevron-down ms-1"></i>
                </button>
            </form>
        @endif
    </div>

    <div class="row g-4">
        <div class="col-12">
            <div class="row g-4">
                {{-- À traiter --}}
                <div class="col-md-4">
                    <div class="card alert-green-card">
                        <div class="position-relative z-index-2">
                            @php($toDecide = ($pending['payments'] ?? 0) + ($pending['yem'] ?? 0))
                            <span class="alert-green-badge">À traiter</span>
                            @if ($pending['payments'] === null && $pending['yem'] === null)
                                <div class="alert-green-date">{{ $currentUserRole }}</div>
                                <div class="alert-green-text">Aucune validation n'est attachée à votre rôle.</div>
                            @else
                                <div class="alert-green-date">{{ now()->translatedFormat('d F Y') }}</div>
                                <div class="alert-green-text">
                                    @if ($toDecide === 0)
                                        Rien n'attend de décision pour le moment.
                                    @else
                                        {{ Format::number($toDecide) }} élément{{ $toDecide > 1 ? 's' : '' }} en attente de décision
                                    @endif
                                </div>
                                <ul class="pending-breakdown">
                                    @if ($pending['payments'] !== null)
                                        <li><i class="bi bi-cash-coin"></i> {{ Format::number($pending['payments']) }} paiement(s) hors prestataire</li>
                                    @endif
                                    @if ($pending['yem'] !== null)
                                        <li><i class="bi bi-arrow-left-right"></i> {{ Format::number($pending['yem']) }} réception(s) / litige(s) YEM</li>
                                    @endif
                                </ul>
                            @endif
                        </div>
                        @if (Route::has('backoffice.validations'))
                            <a href="{{ route('backoffice.validations') }}" class="alert-green-link z-index-2">
                                <span>Ouvrir la file de validation</span>
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        @endif
                        <svg class="alert-green-bg-shape" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <g transform="translate(50,50)">
                                <rect x="-6" y="-45" width="12" height="90" rx="6" ry="6" fill="#B4F105" />
                                <rect x="-6" y="-45" width="12" height="90" rx="6" ry="6" fill="#B4F105" transform="rotate(60)" />
                                <rect x="-6" y="-45" width="12" height="90" rx="6" ry="6" fill="#B4F105" transform="rotate(120)" />
                            </g>
                        </svg>
                    </div>
                </div>

                @if ($summary)
                    {{-- Total collecté --}}
                    <div class="col-md-4">
                        <div class="card card-stat d-flex flex-column justify-content-between">
                            <div>
                                <div class="card-header"><span class="stat-label">Total collecté (net)</span></div>
                                <div class="stat-value">{{ Format::xof($summary['kpis']['total_collected_xof']) }}</div>
                                @if ($trend)
                                    <div class="trend-badge trend-{{ $trend['direction'] }}">
                                        <i class="bi bi-arrow-{{ $trend['direction'] === 'up' ? 'up-right' : 'down-left' }}"></i>
                                        <span>{{ $trend['direction'] === 'up' ? '+' : '−' }}{{ $trend['percent'] }} % par rapport à la période précédente</span>
                                    </div>
                                @else
                                    <div class="trend-badge trend-neutral"><span>{{ Format::number($summary['kpis']['confirmed_payments']) }} paiement(s) confirmé(s)</span></div>
                                @endif
                            </div>
                            <div class="sparkline-container sparkline-card-footer">
                                <div id="collected-sparkline" data-chart="{{ json_encode(['values' => $series['collected_xof'], 'days' => $series['days']]) }}"></div>
                            </div>
                        </div>
                    </div>

                    {{-- Nouveaux adhérents --}}
                    <div class="col-md-4">
                        <div class="card card-stat d-flex flex-column justify-content-between">
                            <div>
                                <div class="card-header"><span class="stat-label">Nouveaux adhérents</span></div>
                                <div class="stat-value">{{ Format::number($summary['kpis']['new_members']) }}</div>
                                <div class="trend-badge trend-neutral">
                                    <span>{{ Format::number($summary['kpis']['registrations']) }} inscription(s) sur la période</span>
                                </div>
                            </div>
                            <div class="sparkline-container sparkline-card-footer">
                                <div id="members-sparkline" data-chart="{{ json_encode(['values' => $series['new_members'], 'days' => $series['days']]) }}"></div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @if ($summary)
            <div class="col-xl-9 col-lg-8">
                <div class="row g-4">
                    <div class="col-12">
                        <div class="card mb-0">
                            <div class="card-header mb-2">
                                <h2 class="card-title">Encaissements par jour</h2>
                                <div class="chart-legend-item">
                                    <span class="legend-dot bg-forest-medium"></span>
                                    <span class="chart-legend-label">Montant net encaissé (FCFA)</span>
                                </div>
                            </div>
                            <div class="d-flex align-items-baseline gap-2 mb-3">
                                <span class="stat-value-amount">{{ Format::xof($summary['kpis']['total_collected_xof']) }}</span>
                                <span class="text-muted-green small">frais de transaction exclus ({{ Format::xof($summary['kpis']['transaction_fees_xof']) }} à la charge des membres)</span>
                            </div>
                            <div id="revenue-chart" data-chart="{{ json_encode(['values' => $series['collected_xof'], 'days' => $series['days']]) }}"></div>
                        </div>
                    </div>

                    <div class="col-md-7 d-flex flex-column">
                        <div class="card h-100 flex-grow-1">
                            <div class="card-header"><h2 class="card-title">Derniers paiements confirmés</h2></div>
                            <div class="transaction-list">
                                @forelse ($recent as $payment)
                                    <div class="transaction-item">
                                        <div class="transaction-icon bg-forest-light text-lime"><i class="bi bi-cash-stack"></i></div>
                                        <div class="transaction-info">
                                            <div class="transaction-name">{{ $payment['label'] }} — {{ $payment['member'] }}</div>
                                            <div class="transaction-date">{{ Format::dateTime($payment['date']) }}</div>
                                        </div>
                                        <div class="transaction-amount text-success">+{{ Format::xof($payment['amount_xof']) }}</div>
                                    </div>
                                @empty
                                    <p class="text-muted-green p-3 mb-0">Aucun paiement confirmé pour le moment.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="col-md-5 d-flex flex-column">
                        <div class="card h-100 flex-grow-1">
                            <div class="card-header"><h2 class="card-title">Répartition des revenus</h2></div>
                            @forelse ($summary['by_source'] as $source)
                                <div class="progress-container">
                                    <div class="progress-label-row">
                                        <span class="progress-label">{{ $source['label'] }}</span>
                                        <span class="progress-value">{{ Format::xof($source['amount_xof']) }}</span>
                                    </div>
                                    <div class="progress" role="progressbar" aria-label="{{ $source['label'] }}" aria-valuenow="{{ intdiv($source['share_bps'], 100) }}" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar bg-lime-accent" style="width: {{ max(2, intdiv($source['share_bps'], 100)) }}%"></div>
                                    </div>
                                </div>
                            @empty
                                <p class="text-muted-green mb-0">Aucun revenu sur la période.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-4">
                <div class="right-panel-wrapper d-flex flex-column gap-4 h-100">
                    <div class="card flex-grow-1 d-flex flex-column justify-content-between mb-0">
                        <div class="card-header mb-1"><h2 class="card-title">Moyens de paiement</h2></div>
                        @if (count($summary['by_method']) > 0)
                            <div id="methods-chart" data-chart="{{ json_encode(['labels' => array_column($summary['by_method'], 'method'), 'values' => array_column($summary['by_method'], 'amount_xof')]) }}"></div>
                            <div class="chart-legends-container">
                                @foreach ($summary['by_method'] as $method)
                                    <div class="chart-legend-item">
                                        <span class="text-muted-green">{{ $method['method'] }} — {{ Format::xof($method['amount_xof']) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-muted-green mb-0">Aucun paiement sur la période.</p>
                        @endif
                    </div>

                    <div class="card mb-0">
                        <div class="card-header mb-2"><h2 class="card-title">Chiffres clés</h2></div>
                        <dl class="key-figures mb-0">
                            <div><dt>Membres inscrits</dt><dd>{{ Format::number($overview['members']) }}</dd></div>
                            <div><dt>Membres actifs</dt><dd>{{ Format::number($overview['active_members']) }}</dd></div>
                            <div><dt>YEM en circulation</dt><dd>{{ Format::yem($overview['yem_in_circulation']) }}</dd></div>
                            <div><dt>Taux de rejet</dt><dd>{{ Format::percent($summary['kpis']['rejection_rate_bps']) }}</dd></div>
                        </dl>
                    </div>

                    @if ($health !== null)
                        <div class="card mb-0">
                            <div class="card-header mb-2"><h2 class="card-title">Santé de la plateforme</h2></div>
                            <ul class="health-list mb-0">
                                @foreach ($health as $check)
                                    <li class="{{ $check['ok'] ? 'is-ok' : 'is-bad' }}" title="{{ $check['ok'] ? '' : $check['message'] }}">
                                        <i class="bi {{ $check['ok'] ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' }}"></i>
                                        {{ str_replace('_', ' ', $check['code']) }}
                                        @if (! $check['ok'])
                                            <span class="health-detail">{{ $check['message'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        @else
            <div class="col-12">
                <div class="card">
                    <p class="mb-0">Votre rôle ne comprend pas la consultation des chiffres de la coopérative. Utilisez le menu pour accéder à vos écrans de travail.</p>
                </div>
            </div>
        @endif
    </div>
@endsection

@if ($summary)
    @push('vendor-scripts')
        <script src="{{ asset('vendor/spark/libs/apexcharts/apexcharts.min.js') }}"></script>
        <script src="{{ asset('vendor/spark/libs/flatpickr/flatpickr.min.js') }}"></script>
    @endpush
    @push('scripts')
        <script src="{{ asset('admin-assets/js/dashboard.js') }}"></script>
    @endpush
@endif
