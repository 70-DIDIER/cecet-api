@php
    use App\Domain\Backoffice\Support\Format;
    use App\Domain\Backoffice\Support\Labels;

    $kpis = $summary['kpis'];
    $yem = $summary['yem_activity'];
    $payouts = $summary['payouts'];
    $methods = ['TMONEY' => 'T-Money', 'FLOOZ' => 'Flooz', 'CASH' => 'Espèces', 'BANK_TRANSFER' => 'Virement bancaire'];
@endphp

<x-filament-panels::page>
    <div class="cect-toolbar">
        <div class="cect-toolbar-field">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="period" aria-label="Période">
                    @foreach ($this->periods() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <p class="cect-muted">Du {{ Format::date($from) }} au {{ Format::date($to) }}</p>
        <x-filament::button color="gray" icon="heroicon-o-printer" x-on:click="window.print()" class="cect-no-print">Imprimer</x-filament::button>
    </div>

    <div class="cect-tiles cect-tiles-3">
        <div class="cect-tile">
            <p class="cect-tile-label">Total collecté</p>
            <p class="cect-tile-value">{{ Format::xof($kpis['total_collected_xof']) }}</p>
            <p class="cect-tile-hint">Frais de transaction : {{ Format::xof($kpis['transaction_fees_xof']) }}</p>
        </div>
        <div class="cect-tile">
            <p class="cect-tile-label">Paiements confirmés</p>
            <p class="cect-tile-value">{{ Format::number($kpis['confirmed_payments']) }}</p>
            <p class="cect-tile-hint">Panier moyen : {{ Format::xof($kpis['average_payment_xof']) }}</p>
        </div>
        <div class="cect-tile">
            <p class="cect-tile-label">Nouveaux adhérents</p>
            <p class="cect-tile-value">{{ Format::number($kpis['new_members']) }}</p>
            <p class="cect-tile-hint">{{ Format::number($kpis['registrations']) }} inscription(s)</p>
        </div>
        <div class="cect-tile">
            <p class="cect-tile-label">Taux de rejet</p>
            <p class="cect-tile-value">{{ Format::percent($kpis['rejection_rate_bps']) }}</p>
            <p class="cect-tile-hint">{{ $kpis['manual_rejections'] }} rejet(s) · {{ $kpis['manual_validations'] }} validation(s)</p>
        </div>
        <div class="cect-tile">
            <p class="cect-tile-label">En attente</p>
            <p class="cect-tile-value">{{ Format::xof($kpis['pending_amount_xof']) }}</p>
            <p class="cect-tile-hint">{{ $kpis['pending_payments'] }} paiement(s)</p>
        </div>
        <div class="cect-tile">
            <p class="cect-tile-label">YEM en circulation</p>
            <p class="cect-tile-value">{{ Format::yem($kpis['yem_in_circulation']) }}</p>
            <p class="cect-tile-hint">Soldes disponibles</p>
        </div>
    </div>

    <div class="cect-columns">
        <x-filament::section>
            <x-slot name="heading">Revenus par source</x-slot>
            @forelse ($summary['by_source'] as $row)
                <div class="cect-bar">
                    <div class="cect-bar-head"><span>{{ $row['label'] }}</span><strong>{{ Format::xof($row['amount_xof']) }} <small>{{ Format::percent($row['share_bps']) }}</small></strong></div>
                    <div class="cect-bar-track"><div class="cect-bar-fill" style="width: {{ min(100, $row['share_bps'] / 100) }}%"></div></div>
                </div>
            @empty
                <p class="cect-muted">Aucun paiement sur la période.</p>
            @endforelse
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Moyens de paiement</x-slot>
            @forelse ($summary['by_method'] as $row)
                <div class="cect-bar">
                    <div class="cect-bar-head"><span>{{ $methods[$row['method']] ?? $row['method'] }} ({{ $row['count'] }})</span><strong>{{ Format::xof($row['amount_xof']) }} <small>{{ Format::percent($row['share_bps']) }}</small></strong></div>
                    <div class="cect-bar-track"><div class="cect-bar-fill" style="width: {{ min(100, $row['share_bps'] / 100) }}%"></div></div>
                </div>
            @empty
                <p class="cect-muted">Aucun paiement sur la période.</p>
            @endforelse
        </x-filament::section>
    </div>

    <div class="cect-columns">
        <x-filament::section>
            <x-slot name="heading">Activité YEM</x-slot>
            <ul class="cect-list">
                <li class="cect-list-row"><span>Vendus à la CECT</span><strong>{{ Format::yem($yem['sold_to_cect']) }} · {{ Format::xof($yem['sold_to_cect_xof']) }}</strong></li>
                <li class="cect-list-row"><span>Payés aux marchands</span><strong>{{ Format::yem($yem['paid_to_merchants']) }} · {{ $yem['merchant_payments'] }} paiement(s)</strong></li>
                <li class="cect-list-row"><span>Rééchangés par les marchands</span><strong>{{ Format::yem($yem['redeemed_by_merchants']) }} · {{ Format::xof($yem['redeemed_by_merchants_xof']) }}</strong></li>
                <li class="cect-list-row"><span>Marchands actifs</span><strong>{{ $yem['active_merchants'] }}</strong></li>
            </ul>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Versements</x-slot>
            <ul class="cect-list">
                <li class="cect-list-row"><span>Versé par le prestataire</span><strong>{{ Format::xof($payouts['paid_by_provider_xof']) }} ({{ $payouts['paid_by_provider_count'] }})</strong></li>
                <li class="cect-list-row"><span>Réglé manuellement</span><strong>{{ Format::xof($payouts['paid_manually_xof']) }} ({{ $payouts['paid_manually_count'] }})</strong></li>
                <li class="cect-list-row"><span>Reste à verser</span><strong>{{ Format::xof($payouts['remaining_due_xof']) }} ({{ $payouts['remaining_due_batches'] }})</strong></li>
                <li class="cect-list-row"><span>À traiter (échecs, règlements manuels)</span><strong>{{ $payouts['batches_needing_action'] }}</strong></li>
            </ul>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Meilleurs membres</x-slot>
        <x-slot name="description">Par montant payé sur la période</x-slot>
        @forelse ($summary['top_members'] as $member)
            <div class="cect-list-row">
                <span><strong>{{ $loop->iteration }}.</strong> {{ $member['name'] }} <span class="cect-muted">{{ $member['member_number'] ?? $member['pernum'] }}</span></span>
                <strong>{{ Format::xof($member['amount_xof']) }} <small>{{ Format::percent($member['share_bps']) }}</small></strong>
            </div>
        @empty
            <p class="cect-muted">Aucun paiement sur la période.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
