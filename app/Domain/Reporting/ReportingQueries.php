<?php

namespace App\Domain\Reporting;

use App\Support\YemQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only figures of the back-office (home, finance, exports), computed from the source tables.
 *
 * "Collected" is what the cooperative receives: the net amount of settled payment intents, transaction fees
 * (borne by the members) excluded and reported apart.
 */
class ReportingQueries
{
    private const TOP_MEMBERS = 5;

    /**
     * @return array<string, mixed>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $paid = $this->paidIntents($from, $to);

        $totals = (clone $paid)->selectRaw('COALESCE(SUM(pi.amount), 0) AS net, COALESCE(SUM(pi.fee_amount), 0) AS fees, COUNT(*) AS count')->first();
        $collected = (int) ($totals->net ?? 0);
        $count = (int) ($totals->count ?? 0);

        $memberships = DB::table('memberships')
            ->selectRaw('COUNT(*) FILTER (WHERE activated_at >= ? AND activated_at < ?) AS activated', [$from, $to])
            ->selectRaw('COUNT(*) FILTER (WHERE created_at >= ? AND created_at < ?) AS registered', [$from, $to])
            ->first();

        $decisions = DB::table('payment_attempts')
            ->where('provider', 'manual')
            ->where('validated_at', '>=', $from)
            ->where('validated_at', '<', $to)
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'approved') AS approved, COUNT(*) FILTER (WHERE status = 'declined') AS rejected")
            ->first();
        $approved = (int) ($decisions->approved ?? 0);
        $rejected = (int) ($decisions->rejected ?? 0);

        $pending = $this->pendingManualPayments();

        return [
            'period' => ['from' => $from->toIso8601ZuluString(), 'to' => $to->toIso8601ZuluString()],
            'kpis' => [
                'total_collected_xof' => $collected,
                'transaction_fees_xof' => (int) ($totals->fees ?? 0),
                'confirmed_payments' => $count,
                'average_payment_xof' => $count === 0 ? 0 : intdiv($collected, $count),
                'new_members' => (int) ($memberships->activated ?? 0),
                'registrations' => (int) ($memberships->registered ?? 0),
                'manual_validations' => $approved,
                'manual_rejections' => $rejected,
                'rejection_rate_bps' => $approved + $rejected === 0 ? 0 : intdiv($rejected * 10000, $approved + $rejected),
                'pending_payments' => $pending['count'],
                'pending_amount_xof' => $pending['amount'],
                'yem_in_circulation' => $this->yemInCirculation(),
            ],
            'yem_activity' => $this->yemActivity($from, $to),
            'payouts' => $this->payoutActivity($from, $to),
            'by_source' => $this->withShare($this->bySource($from, $to), $collected),
            'by_method' => $this->withShare($this->byMethod($from, $to), $collected),
            'top_members' => $this->withShare($this->topMembers($from, $to), $collected),
        ];
    }

    /**
     * Figures of the back-office home page.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $pending = $this->pendingManualPayments();

        return [
            'members' => DB::table('users')->where('kind', 'member')->count(),
            'active_members' => DB::table('users')->where('kind', 'member')->where('status', 'active')->count(),
            'total_collected_xof' => (int) $this->realCollectedIntents()->sum('pi.amount'),
            'pending_validations' => $pending['count'] + DB::table('exchange_requests')->whereIn('status', ['declared_sent', 'disputed'])->count(),
            'yem_in_circulation' => $this->yemInCirculation(),
        ];
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function membersRows(): iterable
    {
        return DB::table('users as u')
            ->leftJoin('organizations as o', 'o.user_id', '=', 'u.id')
            ->leftJoin('memberships as m', 'm.user_id', '=', 'u.id')
            ->where('u.kind', 'member')
            ->select(['u.id', 'u.member_number', 'u.first_name', 'u.last_name', 'u.person_type', 'u.phone', 'u.email', 'u.city', 'u.status', 'o.legal_name', 'm.status as membership_status', 'm.activated_at'])
            ->selectSub($this->pernumOf('u.id'), 'pernum')
            ->selectSub($this->activeFamiliesOf('u.id'), 'active_families')
            ->selectSub($this->balanceOf('u.id'), 'yem_balance')
            ->orderBy('u.created_at')
            ->orderBy('u.id')
            ->lazy()
            ->map(fn (object $row): array => [
                'Numéro membre' => $row->member_number,
                'Nom' => $row->legal_name ?? trim("{$row->first_name} {$row->last_name}"),
                'Pernum' => $row->pernum,
                'Type' => $row->person_type === 'legal' ? 'Morale' : 'Physique',
                'Téléphone' => $row->phone,
                'Email' => $row->email,
                'Ville' => $row->city,
                'Statut compte' => $row->status,
                'Adhésion' => $row->membership_status,
                'Membre depuis' => $row->activated_at,
                'Packs actifs' => $row->active_families,
                'YEM disponibles' => YemQuantity::of((string) $row->yem_balance),
            ]);
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function paymentsRows(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return $this->paidIntents($from, $to)
            ->join('users as u', 'u.id', '=', 'pi.user_id')
            ->leftJoin('organizations as o', 'o.user_id', '=', 'u.id')
            ->leftJoin('user_packs as up', fn ($join) => $join->on('up.id', '=', 'pi.payable_id')->where('pi.payable_type', '=', 'user_pack'))
            ->select(['pi.id', 'pi.succeeded_at', 'pi.purpose', 'pi.amount', 'pi.fee_amount', 'pi.total_amount', 'u.member_number', 'u.first_name', 'u.last_name', 'o.legal_name', 'up.family'])
            ->selectSub($this->pernumOf('u.id'), 'pernum')
            ->selectSub(DB::table('payment_attempts')->whereColumn('payment_intent_id', 'pi.id')->where('status', 'approved')->orderBy('settled_at')->limit(1)->selectRaw('COALESCE(method, provider)'), 'method')
            ->orderBy('pi.succeeded_at')
            ->orderBy('pi.id')
            ->lazy()
            ->map(fn (object $row): array => [
                'Date' => $row->succeeded_at,
                'Numéro membre' => $row->member_number,
                'Membre' => $row->legal_name ?? trim("{$row->first_name} {$row->last_name}"),
                'Pernum' => $row->pernum,
                'Objet' => $this->sourceLabel($this->sourceKey($row->purpose, $row->family)),
                'Montant net (FCFA)' => (int) $row->amount,
                'Frais (FCFA)' => (int) $row->fee_amount,
                'Total payé (FCFA)' => (int) $row->total_amount,
                'Mode de paiement' => $row->method,
                'Référence' => $row->id,
            ]);
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function subscriptionsRows(): iterable
    {
        return DB::table('user_packs as up')
            ->join('users as u', 'u.id', '=', 'up.user_id')
            ->leftJoin('organizations as o', 'o.user_id', '=', 'u.id')
            ->join('pack_plans as pp', 'pp.id', '=', 'up.pack_plan_id')
            ->join('pack_versions as pv', 'pv.id', '=', 'up.pack_version_id')
            ->leftJoin('quota_accounts as qa', 'qa.user_pack_id', '=', 'up.id')
            ->where('up.status', 'active')
            ->select(['up.id', 'up.family', 'up.price_xof', 'up.activated_at', 'up.expires_at', 'pp.code', 'pv.quota_per_period', 'pv.period_cycles', 'qa.available', 'qa.consumed', 'u.member_number', 'u.first_name', 'u.last_name', 'o.legal_name'])
            ->selectSub($this->pernumOf('u.id'), 'pernum')
            ->orderBy('up.activated_at')
            ->orderBy('up.id')
            ->lazy()
            ->map(fn (object $row): array => [
                'Numéro membre' => $row->member_number,
                'Membre' => $row->legal_name ?? trim("{$row->first_name} {$row->last_name}"),
                'Pernum' => $row->pernum,
                'Famille' => $row->family,
                'Pack' => $row->code,
                'Prix payé (FCFA)' => (int) $row->price_xof,
                'Début' => $row->activated_at,
                'Fin' => $row->expires_at,
                'Quota par période' => $row->quota_per_period,
                'Périodes' => $row->period_cycles,
                'YEM disponibles' => $row->available,
                'YEM consommés' => $row->consumed,
            ]);
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function merchantPaymentsRows(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return DB::table('merchant_payments as mp')
            ->join('users as p', 'p.id', '=', 'mp.payer_id')
            ->join('users as m', 'm.id', '=', 'mp.merchant_id')
            ->leftJoin('organizations as po', 'po.user_id', '=', 'p.id')
            ->leftJoin('organizations as mo', 'mo.user_id', '=', 'm.id')
            ->join('user_packs as up', 'up.id', '=', 'mp.payer_pack_id')
            ->where('mp.created_at', '>=', $from)
            ->where('mp.created_at', '<', $to)
            ->select(['mp.reference', 'mp.created_at', 'mp.status', 'mp.quantity', 'mp.payer_pernum', 'mp.merchant_pernum', 'mp.outlet_label', 'mp.confirmation_mode', 'mp.confirmed_at', 'up.family',
                'p.first_name as payer_first_name', 'p.last_name as payer_last_name', 'po.legal_name as payer_legal_name',
                'm.first_name as merchant_first_name', 'm.last_name as merchant_last_name', 'mo.legal_name as merchant_legal_name'])
            ->orderBy('mp.created_at')
            ->orderBy('mp.id')
            ->lazy()
            ->map(fn (object $row): array => [
                'Référence' => $row->reference,
                'Date' => $row->created_at,
                'Payeur' => $row->payer_legal_name ?? trim("{$row->payer_first_name} {$row->payer_last_name}"),
                'Pernum payeur' => $row->payer_pernum,
                'Pack utilisé' => $row->family,
                'Marchand' => $row->merchant_legal_name ?? trim("{$row->merchant_first_name} {$row->merchant_last_name}"),
                'Pernum marchand' => $row->merchant_pernum,
                'Point de vente' => $row->outlet_label,
                'YEM' => $row->quantity,
                'Statut' => $row->status,
                'Confirmation' => $row->confirmation_mode,
                'Confirmé le' => $row->confirmed_at,
            ]);
    }

    /**
     * Exchange requests created in the selected period.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function exchangesRows(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return DB::table('exchange_requests as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('organizations as o', 'o.user_id', '=', 'u.id')
            ->where('e.created_at', '>=', $from)
            ->where('e.created_at', '<', $to)
            ->select([
                'e.reference', 'e.kind', 'e.status', 'e.quantity_requested', 'e.quantity_received',
                'e.rate_xof_per_yem', 'e.amount_due_xof', 'e.source_pernum', 'e.cect_pernum',
                'e.member_transfer_reference', 'e.declared_at', 'e.validated_at', 'e.closed_at',
                'e.created_at', 'u.member_number', 'u.first_name', 'u.last_name', 'o.legal_name',
            ])
            ->orderBy('e.created_at')
            ->orderBy('e.id')
            ->lazy()
            ->map(fn (object $row): array => [
                'Référence' => $row->reference,
                'Date' => $row->created_at,
                'Type' => $row->kind,
                'Membre' => $row->legal_name ?? trim("{$row->first_name} {$row->last_name}"),
                'Numéro membre' => $row->member_number,
                'Pernum source' => $row->source_pernum,
                'Pernum CECT' => $row->cect_pernum,
                'YEM demandés' => $row->quantity_requested,
                'YEM reçus' => $row->quantity_received,
                'Cours FCFA/YEM' => $row->rate_xof_per_yem,
                'Montant dû (FCFA)' => $row->amount_due_xof,
                'Statut' => $row->status,
                'Référence transfert membre' => $row->member_transfer_reference,
                'Déclaré le' => $row->declared_at,
                'Validé le' => $row->validated_at,
                'Clôturé le' => $row->closed_at,
            ]);
    }

    /**
     * Payout batches and their routing state for the selected period.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function payoutsRows(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return DB::table('payout_batches as b')
            ->join('users as u', 'u.id', '=', 'b.beneficiary_id')
            ->leftJoin('organizations as o', 'o.user_id', '=', 'u.id')
            ->where('b.created_at', '>=', $from)
            ->where('b.created_at', '<', $to)
            ->select([
                'b.reference', 'b.total_amount', 'b.paid_amount', 'b.remaining_amount', 'b.route',
                'b.method', 'b.status', 'b.created_at', 'b.updated_at', 'u.member_number',
                'u.first_name', 'u.last_name', 'o.legal_name',
            ])
            ->orderBy('b.created_at')
            ->orderBy('b.id')
            ->lazy()
            ->map(fn (object $row): array => [
                'Référence' => $row->reference,
                'Date' => $row->created_at,
                'Bénéficiaire' => $row->legal_name ?? trim("{$row->first_name} {$row->last_name}"),
                'Numéro membre' => $row->member_number,
                'Montant total (FCFA)' => (int) $row->total_amount,
                'Montant payé (FCFA)' => (int) $row->paid_amount,
                'Reste dû (FCFA)' => (int) $row->remaining_amount,
                'Route' => $row->route,
                'Moyen' => $row->method,
                'Statut' => $row->status,
                'Dernière mise à jour' => $row->updated_at,
            ]);
    }

    /**
     * Authorised business audit export for the selected period.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function auditRows(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return DB::table('audit_events')
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->select(['position', 'occurred_at', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'result', 'correlation_id', 'hash'])
            ->orderBy('position')
            ->lazy()
            ->map(fn (object $row): array => [
                'Position' => $row->position,
                'Date' => $row->occurred_at,
                'Type acteur' => $row->actor_type,
                'Acteur' => $row->actor_id,
                'Action' => $row->action,
                'Type objet' => $row->subject_type,
                'Objet' => $row->subject_id,
                'Résultat' => $row->result,
                'Corrélation' => $row->correlation_id,
                'Empreinte' => $row->hash,
            ]);
    }

    public function pernumOf(string $userColumn): Builder
    {
        return DB::table('pernum_identities')->whereColumn('user_id', $userColumn)->where('status', '!=', 'revoked')->orderBy('created_at')->limit(1)->select('pernum');
    }

    public function membershipStatusOf(string $userColumn): Builder
    {
        return DB::table('memberships')->whereColumn('user_id', $userColumn)->limit(1)->select('status');
    }

    public function activeFamiliesOf(string $userColumn): Builder
    {
        return DB::table('user_packs')->whereColumn('user_id', $userColumn)->where('status', 'active')->selectRaw("COALESCE(string_agg(DISTINCT family, ',' ORDER BY family), '')");
    }

    public function balanceOf(string $userColumn): Builder
    {
        return DB::table('quota_accounts as qa')
            ->join('user_packs as up', 'up.id', '=', 'qa.user_pack_id')
            ->whereColumn('up.user_id', $userColumn)
            ->where('up.status', 'active')
            ->whereIn('up.family', ['CONSOMMATEUR', 'PERSONNEL'])
            ->selectRaw('COALESCE(SUM(qa.available), 0)');
    }

    /**
     * YEM movements of the period: sold to the CECT (Vendeur), paid to merchants, redeemed by merchants.
     *
     * @return array<string, mixed>
     */
    private function yemActivity(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $exchanges = DB::table('exchange_requests')
            ->whereNotNull('quantity_received')
            ->where('validated_at', '>=', $from)
            ->where('validated_at', '<', $to)
            ->selectRaw("COALESCE(SUM(quantity_received) FILTER (WHERE kind = 'seller'), 0) AS sold")
            ->selectRaw("COALESCE(SUM(amount_due_xof) FILTER (WHERE kind = 'seller'), 0) AS sold_xof")
            ->selectRaw("COALESCE(SUM(quantity_received) FILTER (WHERE kind = 'merchant_redemption'), 0) AS redeemed")
            ->selectRaw("COALESCE(SUM(amount_due_xof) FILTER (WHERE kind = 'merchant_redemption'), 0) AS redeemed_xof")
            ->first();

        $merchantPayments = DB::table('merchant_payments')
            ->where('status', 'confirmed')
            ->where('confirmed_at', '>=', $from)
            ->where('confirmed_at', '<', $to)
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(quantity), 0) AS quantity, COUNT(DISTINCT merchant_id) AS merchants')
            ->first();

        return [
            'sold_to_cect' => YemQuantity::of((string) ($exchanges->sold ?? '0')),
            'sold_to_cect_xof' => (int) ($exchanges->sold_xof ?? 0),
            'paid_to_merchants' => YemQuantity::of((string) ($merchantPayments->quantity ?? '0')),
            'merchant_payments' => (int) ($merchantPayments->count ?? 0),
            'merchants_paid' => (int) ($merchantPayments->merchants ?? 0),
            'redeemed_by_merchants' => YemQuantity::of((string) ($exchanges->redeemed ?? '0')),
            'redeemed_by_merchants_xof' => (int) ($exchanges->redeemed_xof ?? 0),
            'active_merchants' => DB::table('user_packs')->where('family', 'MARCHAND')->where('status', 'active')->distinct()->count('user_id'),
        ];
    }

    /**
     * Counter-values paid out during the period, failures, and what is still owed now.
     *
     * @return array<string, int>
     */
    private function payoutActivity(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $items = DB::table('payout_items')
            ->where('settled_at', '>=', $from)
            ->where('settled_at', '<', $to)
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'succeeded') AS paid_count")
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE status = 'succeeded'), 0) AS paid_amount")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'failed') AS failed_count")
            ->first();

        $manual = DB::table('manual_settlements')
            ->where('status', 'applied')
            ->where('decided_at', '>=', $from)
            ->where('decided_at', '<', $to)
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')
            ->first();

        $owed = DB::table('payout_batches')
            ->whereNotIn('status', ['paid'])
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(remaining_amount), 0) AS amount')
            ->selectRaw("COUNT(*) FILTER (WHERE status IN ('failed', 'manual_required')) AS needing_action")
            ->first();

        return [
            'paid_by_provider_xof' => (int) ($items->paid_amount ?? 0),
            'paid_by_provider_count' => (int) ($items->paid_count ?? 0),
            'failed_transfers' => (int) ($items->failed_count ?? 0),
            'paid_manually_xof' => (int) ($manual->amount ?? 0),
            'paid_manually_count' => (int) ($manual->count ?? 0),
            'remaining_due_xof' => (int) ($owed->amount ?? 0),
            'remaining_due_batches' => (int) ($owed->count ?? 0),
            'batches_needing_action' => (int) ($owed->needing_action ?? 0),
            'manual_settlements_pending' => DB::table('manual_settlements')->where('status', 'pending_approval')->count(),
        ];
    }

    /**
     * Day-by-day figures of the period (UTC days), for the dashboard charts.
     *
     * @return array{days: list<string>, collected_xof: list<int>, new_members: list<int>}
     */
    public function dailySeries(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $collected = $this->paidIntents($from, $to)
            ->selectRaw("to_char(pi.succeeded_at at time zone 'UTC', 'YYYY-MM-DD') AS day, SUM(pi.amount) AS amount")
            ->groupBy('day')
            ->pluck('amount', 'day');

        $members = DB::table('memberships')
            ->where('activated_at', '>=', $from)
            ->where('activated_at', '<', $to)
            ->selectRaw("to_char(activated_at at time zone 'UTC', 'YYYY-MM-DD') AS day, COUNT(*) AS count")
            ->groupBy('day')
            ->pluck('count', 'day');

        $days = [];
        $amounts = [];
        $newMembers = [];

        for ($day = $from->utc()->startOfDay(); $day->lessThan($to); $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $days[] = $key;
            $amounts[] = (int) ($collected[$key] ?? 0);
            $newMembers[] = (int) ($members[$key] ?? 0);
        }

        return ['days' => $days, 'collected_xof' => $amounts, 'new_members' => $newMembers];
    }

    /**
     * The latest confirmed payments, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function recentPayments(int $limit = 6): array
    {
        return array_values(DB::table('payment_intents as pi')
            ->join('users as u', 'u.id', '=', 'pi.user_id')
            ->leftJoin('organizations as o', 'o.user_id', '=', 'u.id')
            ->leftJoin('user_packs as up', fn ($join) => $join->on('up.id', '=', 'pi.payable_id')->where('pi.payable_type', '=', 'user_pack'))
            ->where('pi.status', 'succeeded')
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('payment_attempts as pa')
                ->whereColumn('pa.payment_intent_id', 'pi.id')
                ->where('pa.status', 'approved')
                ->where('pa.provider', '<>', 'fake'))
            ->orderByDesc('pi.succeeded_at')
            ->limit($limit)
            ->select(['pi.id', 'pi.purpose', 'pi.amount', 'pi.succeeded_at', 'up.family', 'u.first_name', 'u.last_name', 'o.legal_name'])
            ->selectSub(
                DB::table('payment_attempts as pa')
                    ->selectRaw('COALESCE(pa.method, pa.provider)')
                    ->whereColumn('pa.payment_intent_id', 'pi.id')
                    ->where('pa.status', 'approved')
                    ->orderByDesc('pa.created_at')
                    ->limit(1),
                'method',
            )
            ->get()
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'label' => $this->sourceLabel($this->sourceKey($row->purpose, $row->family)),
                'member' => $row->legal_name ?? trim("{$row->first_name} {$row->last_name}"),
                'method' => $row->method,
                'amount_xof' => (int) $row->amount,
                'date' => CarbonImmutable::parse($row->succeeded_at),
            ])
            ->values()
            ->all());
    }

    /**
     * Items awaiting a decision of the validators.
     *
     * @return array{payments: int, yem: int, merchant: int}
     */
    public function pendingValidations(): array
    {
        return [
            'payments' => $this->pendingManualPayments()['count'],
            'yem' => DB::table('exchange_requests')->whereIn('status', ['declared_sent', 'disputed'])->count(),
            'merchant' => DB::table('merchant_payments')
                ->where(fn ($query) => $query->where('status', 'disputed')->orWhere(fn ($declared) => $declared->where('status', 'declared_sent')->where('confirmation_mode', 'validator')))
                ->count(),
        ];
    }

    /**
     * Only actual confirmed provider receipts count as collected cash.
     * Fake/simulation provider payments must never inflate financial KPIs.
     */
    private function realCollectedIntents(): Builder
    {
        return DB::table('payment_intents as pi')
            ->where('pi.status', 'succeeded')
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('payment_attempts as pa')
                ->whereColumn('pa.payment_intent_id', 'pi.id')
                ->where('pa.status', 'approved')
                ->where('pa.provider', '<>', 'fake'));
    }

    private function paidIntents(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->realCollectedIntents()
            ->where('pi.succeeded_at', '>=', $from)
            ->where('pi.succeeded_at', '<', $to);
    }

    /**
     * @return array{count: int, amount: int}
     */
    private function pendingManualPayments(): array
    {
        $row = DB::table('payment_attempts')
            ->where('provider', 'manual')
            ->where('status', 'pending')
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount - fee_amount), 0) AS amount')
            ->first();

        return ['count' => (int) ($row->count ?? 0), 'amount' => (int) ($row->amount ?? 0)];
    }

    private function yemInCirculation(): string
    {
        $sum = DB::table('quota_accounts as qa')
            ->join('user_packs as up', 'up.id', '=', 'qa.user_pack_id')
            ->where('up.status', 'active')
            ->sum('qa.available');

        return YemQuantity::of((string) $sum);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bySource(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values($this->paidIntents($from, $to)
            ->leftJoin('user_packs as up', fn ($join) => $join->on('up.id', '=', 'pi.payable_id')->where('pi.payable_type', '=', 'user_pack'))
            ->groupBy('pi.purpose', 'up.family')
            ->selectRaw('pi.purpose, up.family, SUM(pi.amount) AS amount, COUNT(*) AS count')
            ->get()
            ->groupBy(fn (object $row): string => $this->sourceKey($row->purpose, $row->family))
            ->map(fn ($rows, string $key): array => [
                'key' => $key,
                'label' => $this->sourceLabel($key),
                'count' => (int) $rows->sum('count'),
                'amount_xof' => (int) $rows->sum('amount'),
            ])
            ->sortByDesc('amount_xof')
            ->values()
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byMethod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values($this->paidIntents($from, $to)
            ->join('payment_attempts as pa', fn ($join) => $join->on('pa.payment_intent_id', '=', 'pi.id')->where('pa.status', '=', 'approved')->where('pa.requires_review', '=', false))
            ->groupByRaw('COALESCE(pa.method, pa.provider)')
            ->selectRaw('COALESCE(pa.method, pa.provider) AS method, SUM(pi.amount) AS amount, COUNT(*) AS count')
            ->orderByDesc('amount')
            ->get()
            ->map(fn (object $row): array => ['method' => $row->method, 'count' => (int) $row->count, 'amount_xof' => (int) $row->amount])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topMembers(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values($this->paidIntents($from, $to)
            ->join('users as u', 'u.id', '=', 'pi.user_id')
            ->leftJoin('organizations as o', 'o.user_id', '=', 'u.id')
            ->groupBy('u.id', 'u.first_name', 'u.last_name', 'u.person_type', 'u.member_number', 'o.legal_name')
            ->select(['u.id', 'u.first_name', 'u.last_name', 'u.person_type', 'u.member_number', 'o.legal_name'])
            ->selectRaw('SUM(pi.amount) AS amount, COUNT(*) AS count')
            ->selectSub($this->pernumOf('u.id'), 'pernum')
            ->orderByDesc('amount')
            ->limit(self::TOP_MEMBERS)
            ->get()
            ->values()
            ->map(fn (object $row, int $index): array => [
                'rank' => $index + 1,
                'user_id' => $row->id,
                'name' => $row->legal_name ?? trim("{$row->first_name} {$row->last_name}"),
                'member_number' => $row->member_number,
                'pernum' => $row->pernum,
                'person_type' => $row->person_type,
                'count' => (int) $row->count,
                'amount_xof' => (int) $row->amount,
            ])
            ->all());
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withShare(array $rows, int $total): array
    {
        return array_map(fn (array $row): array => [...$row, 'share_bps' => $total === 0 ? 0 : intdiv($row['amount_xof'] * 10000, $total)], $rows);
    }

    private function sourceKey(string $purpose, ?string $family): string
    {
        return match ($purpose) {
            'membership' => 'membership',
            'pack_topup' => 'pack_topup',
            default => 'pack_'.strtolower($family ?? 'unknown'),
        };
    }

    private function sourceLabel(string $key): string
    {
        return match ($key) {
            'membership' => 'Adhésions',
            'pack_topup' => 'Rechargements Perso',
            'pack_vendeur' => 'Droit d\'accès Vendeur',
            'pack_consommateur' => 'Packs Consommateur',
            'pack_marchand' => 'Droit d\'accès Marchand',
            'pack_personnel' => 'Pack Perso',
            default => 'Autres',
        };
    }
}
