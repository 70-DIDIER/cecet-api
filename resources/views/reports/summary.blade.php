@php
    $xof = fn ($value) => number_format((int) $value, 0, ',', ' ').' FCFA';
    $num = fn ($value) => number_format((int) $value, 0, ',', ' ');
    $yem = fn ($value) => number_format((float) $value, 2, ',', ' ').' YEM';
    $pct = fn ($bps) => number_format(((int) $bps) / 100, 1, ',', ' ').' %';
    $kpis = $summary['kpis'];
    $activity = $summary['yem_activity'];
    $payouts = $summary['payouts'];
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rapport CECT — {{ $from->format('d/m/Y') }} au {{ $lastDay->format('d/m/Y') }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { font-family: "Segoe UI", Arial, sans-serif; color: #1d2733; background: #fff; margin: 0; padding: 32px; font-size: 13px; line-height: 1.45; }
        main { max-width: 900px; margin: 0 auto; }
        header { border-bottom: 3px solid #1c7a43; padding-bottom: 12px; margin-bottom: 24px; }
        h1 { font-size: 22px; margin: 0 0 4px; color: #11502c; }
        h2 { font-size: 15px; margin: 28px 0 10px; color: #11502c; border-bottom: 1px solid #d5dde3; padding-bottom: 4px; }
        .muted { color: #5b6876; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .kpi { border: 1px solid #d5dde3; border-radius: 8px; padding: 10px 12px; break-inside: avoid; }
        .kpi .label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #5b6876; }
        .kpi .value { font-size: 17px; font-weight: 700; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; break-inside: auto; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e6ebef; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #5b6876; }
        td.number, th.number { text-align: right; font-variant-numeric: tabular-nums; }
        tr { break-inside: avoid; }
        footer { margin-top: 32px; font-size: 11px; color: #5b6876; border-top: 1px solid #d5dde3; padding-top: 8px; }
        @media (max-width: 640px) { body { padding: 16px; } .grid { grid-template-columns: 1fr 1fr; } }
        @media print { body { padding: 0; } @page { margin: 16mm; } }
    </style>
</head>
<body>
<main>
    <header>
        <h1>Rapport d'activité de la CECT</h1>
        <div class="muted">Période du {{ $from->format('d/m/Y') }} au {{ $lastDay->format('d/m/Y') }} — généré le {{ $generatedAt->format('d/m/Y à H:i') }} (UTC)</div>
    </header>

    <h2>Indicateurs clés</h2>
    <div class="grid">
        <div class="kpi"><div class="label">Total collecté (net)</div><div class="value">{{ $xof($kpis['total_collected_xof']) }}</div></div>
        <div class="kpi"><div class="label">Paiements confirmés</div><div class="value">{{ $num($kpis['confirmed_payments']) }}</div><div class="muted">Panier moyen : {{ $xof($kpis['average_payment_xof']) }}</div></div>
        <div class="kpi"><div class="label">Nouveaux adhérents</div><div class="value">{{ $num($kpis['new_members']) }}</div><div class="muted">{{ $num($kpis['registrations']) }} inscription(s)</div></div>
        <div class="kpi"><div class="label">Taux de rejet</div><div class="value">{{ $pct($kpis['rejection_rate_bps']) }}</div><div class="muted">{{ $num($kpis['manual_rejections']) }} rejet(s), {{ $num($kpis['manual_validations']) }} validation(s)</div></div>
        <div class="kpi"><div class="label">En attente (actuel)</div><div class="value">{{ $xof($kpis['pending_amount_xof']) }}</div><div class="muted">{{ $num($kpis['pending_payments']) }} paiement(s)</div></div>
        <div class="kpi"><div class="label">YEM en circulation</div><div class="value">{{ $yem($kpis['yem_in_circulation']) }}</div><div class="muted">Soldes disponibles</div></div>
    </div>
    <p class="muted">Frais de transaction payés par les membres (non inclus) : {{ $xof($kpis['transaction_fees_xof']) }}.</p>

    <h2>Répartition des revenus</h2>
    <table>
        <thead><tr><th>Source</th><th class="number">Opérations</th><th class="number">Montant</th><th class="number">Part</th></tr></thead>
        <tbody>
        @forelse ($summary['by_source'] as $row)
            <tr><td>{{ $row['label'] }}</td><td class="number">{{ $num($row['count']) }}</td><td class="number">{{ $xof($row['amount_xof']) }}</td><td class="number">{{ $pct($row['share_bps']) }}</td></tr>
        @empty
            <tr><td colspan="4" class="muted">Aucun paiement sur la période.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Moyens de paiement</h2>
    <table>
        <thead><tr><th>Moyen</th><th class="number">Opérations</th><th class="number">Montant</th><th class="number">Part</th></tr></thead>
        <tbody>
        @forelse ($summary['by_method'] as $row)
            <tr><td>{{ $row['method'] }}</td><td class="number">{{ $num($row['count']) }}</td><td class="number">{{ $xof($row['amount_xof']) }}</td><td class="number">{{ $pct($row['share_bps']) }}</td></tr>
        @empty
            <tr><td colspan="4" class="muted">Aucun paiement sur la période.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Membres les plus actifs</h2>
    <table>
        <thead><tr><th>#</th><th>Membre</th><th>Pernum</th><th class="number">Opérations</th><th class="number">Montant</th><th class="number">Part</th></tr></thead>
        <tbody>
        @forelse ($summary['top_members'] as $row)
            <tr><td>{{ $row['rank'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['pernum'] ?? '—' }}</td><td class="number">{{ $num($row['count']) }}</td><td class="number">{{ $xof($row['amount_xof']) }}</td><td class="number">{{ $pct($row['share_bps']) }}</td></tr>
        @empty
            <tr><td colspan="6" class="muted">Aucun paiement sur la période.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Activité YEM</h2>
    <table>
        <tbody>
            <tr><td>YEM vendus à la CECT (Vendeurs)</td><td class="number">{{ $yem($activity['sold_to_cect']) }}</td><td class="number">{{ $xof($activity['sold_to_cect_xof']) }}</td></tr>
            <tr><td>YEM payés aux marchands ({{ $num($activity['merchant_payments']) }} paiement(s), {{ $num($activity['merchants_paid']) }} marchand(s))</td><td class="number">{{ $yem($activity['paid_to_merchants']) }}</td><td></td></tr>
            <tr><td>YEM rééchangés par les marchands</td><td class="number">{{ $yem($activity['redeemed_by_merchants']) }}</td><td class="number">{{ $xof($activity['redeemed_by_merchants_xof']) }}</td></tr>
            <tr><td>Marchands actifs</td><td class="number">{{ $num($activity['active_merchants']) }}</td><td></td></tr>
        </tbody>
    </table>

    <h2>Contre-valeurs (payouts)</h2>
    <table>
        <tbody>
            <tr><td>Versées par le prestataire ({{ $num($payouts['paid_by_provider_count']) }} virement(s))</td><td class="number">{{ $xof($payouts['paid_by_provider_xof']) }}</td></tr>
            <tr><td>Réglées manuellement ({{ $num($payouts['paid_manually_count']) }} règlement(s))</td><td class="number">{{ $xof($payouts['paid_manually_xof']) }}</td></tr>
            <tr><td>Virements en échec sur la période</td><td class="number">{{ $num($payouts['failed_transfers']) }}</td></tr>
            <tr><td>Reste dû à ce jour ({{ $num($payouts['remaining_due_batches']) }} dossier(s), dont {{ $num($payouts['batches_needing_action']) }} à traiter)</td><td class="number">{{ $xof($payouts['remaining_due_xof']) }}</td></tr>
            <tr><td>Règlements manuels en attente d'approbation</td><td class="number">{{ $num($payouts['manual_settlements_pending']) }}</td></tr>
        </tbody>
    </table>

    <footer>
        Montants nets perçus par la coopérative, frais de transaction exclus. Les soldes YEM affichés sont des droits suivis par la plateforme, non une monnaie.
        Document généré par la plateforme CECT ; la plateforme reste la seule source de vérité.
    </footer>
</main>
</body>
</html>
