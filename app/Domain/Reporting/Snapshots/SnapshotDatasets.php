<?php

namespace App\Domain\Reporting\Snapshots;

use Illuminate\Support\Facades\DB;

/**
 * The datasets of a consolidated snapshot (cahier v4.0 §10.1: memberships, packs, quotas, sales/redemptions,
 * payments, authorised audit). Column names are stable: changing them requires a new schema version.
 */
class SnapshotDatasets
{
    public const SCHEMA_VERSION = 1;

    /**
     * @return array<string, iterable<int, array<string, mixed>>>
     */
    public function all(): array
    {
        return [
            'membres.csv' => $this->members(),
            'packs.csv' => $this->packs(),
            'journal_quota.csv' => $this->quotaLedger(),
            'echanges.csv' => $this->exchanges(),
            'paiements.csv' => $this->payments(),
            'paiements_marchands.csv' => $this->merchantPayments(),
            'soldes_marchands.csv' => $this->merchantBalances(),
            'payouts.csv' => $this->payouts(),
            'audit.csv' => $this->audit(),
        ];
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function members(): iterable
    {
        return DB::table('users as u')
            ->leftJoin('organizations as o', 'o.user_id', '=', 'u.id')
            ->leftJoin('memberships as m', 'm.user_id', '=', 'u.id')
            ->where('u.kind', 'member')
            ->select(['u.id', 'u.member_number', 'u.person_type', 'u.first_name', 'u.last_name', 'o.legal_name', 'u.email', 'u.phone', 'u.city', 'u.status', 'u.created_at',
                'm.status as membership_status', 'm.fee_amount', 'm.paid_at', 'm.activated_at', 'm.activation_mode'])
            ->selectSub(DB::table('pernum_identities')->whereColumn('user_id', 'u.id')->where('status', '!=', 'revoked')->orderBy('created_at')->limit(1)->select('pernum'), 'pernum')
            ->orderBy('u.id')
            ->lazy()
            ->map(fn (object $row): array => [
                'user_id' => $row->id,
                'member_number' => $row->member_number,
                'pernum' => $row->pernum,
                'person_type' => $row->person_type,
                'first_name' => $row->first_name,
                'last_name' => $row->last_name,
                'legal_name' => $row->legal_name,
                'email' => $row->email,
                'phone' => $row->phone,
                'city' => $row->city,
                'account_status' => $row->status,
                'membership_status' => $row->membership_status,
                'membership_fee_xof' => $row->fee_amount,
                'membership_paid_at' => $row->paid_at,
                'membership_activated_at' => $row->activated_at,
                'activation_mode' => $row->activation_mode,
                'created_at' => $row->created_at,
            ]);
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function packs(): iterable
    {
        return DB::table('user_packs as up')
            ->join('pack_plans as pp', 'pp.id', '=', 'up.pack_plan_id')
            ->join('pack_versions as pv', 'pv.id', '=', 'up.pack_version_id')
            ->leftJoin('quota_accounts as qa', 'qa.user_pack_id', '=', 'up.id')
            ->select(['up.id', 'up.user_id', 'up.family', 'pp.code', 'pv.version', 'up.status', 'up.price_xof', 'pv.quota_per_period', 'pv.period_unit', 'pv.period_cycles',
                'up.paid_at', 'up.activated_at', 'up.expires_at', 'qa.available', 'qa.reserved', 'qa.consumed'])
            ->orderBy('up.id')
            ->lazy()
            ->map(fn (object $row): array => [
                'user_pack_id' => $row->id,
                'user_id' => $row->user_id,
                'family' => $row->family,
                'plan_code' => $row->code,
                'version' => $row->version,
                'status' => $row->status,
                'price_xof' => $row->price_xof,
                'quota_per_period' => $row->quota_per_period,
                'period_unit' => $row->period_unit,
                'period_cycles' => $row->period_cycles,
                'paid_at' => $row->paid_at,
                'activated_at' => $row->activated_at,
                'expires_at' => $row->expires_at,
                'available' => $row->available,
                'reserved' => $row->reserved,
                'consumed' => $row->consumed,
            ]);
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function quotaLedger(): iterable
    {
        return DB::table('quota_ledger_entries')
            ->select(['id', 'user_pack_id', 'type', 'available_delta', 'reserved_delta', 'consumed_delta', 'available_after', 'reserved_after', 'consumed_after', 'source_type', 'source_id', 'reason', 'actor_id', 'created_at'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->lazy()
            ->map(fn (object $row): array => (array) $row);
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function exchanges(): iterable
    {
        return DB::table('exchange_requests')
            ->select(['id', 'reference', 'kind', 'user_id', 'user_pack_id', 'status', 'quantity_requested', 'quantity_received', 'rate_setting_key', 'rate_xof_per_yem', 'amount_due_xof',
                'source_pernum', 'cect_pernum', 'member_transfer_reference', 'declared_at', 'validated_at', 'validated_by', 'closed_at', 'created_at'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->lazy()
            ->map(fn (object $row): array => (array) $row);
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function payments(): iterable
    {
        return DB::table('payment_attempts as pa')
            ->join('payment_intents as pi', 'pi.id', '=', 'pa.payment_intent_id')
            ->select(['pa.id', 'pi.id as intent_id', 'pi.user_id', 'pi.purpose', 'pi.payable_type', 'pi.payable_id', 'pi.status as intent_status', 'pa.status', 'pa.provider', 'pa.method',
                'pi.amount as net_amount', 'pa.fee_amount', 'pa.amount as total_amount', 'pa.provider_transaction_id', 'pa.declared_reference', 'pa.validated_by', 'pa.validated_at',
                'pa.requires_review', 'pa.settled_at', 'pa.created_at'])
            ->orderBy('pa.created_at')
            ->orderBy('pa.id')
            ->lazy()
            ->map(fn (object $row): array => [...(array) $row, 'requires_review' => $row->requires_review ? '1' : '0']);
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function merchantPayments(): iterable
    {
        return DB::table('merchant_payments')
            ->select(['id', 'reference', 'payer_id', 'payer_pack_id', 'merchant_id', 'merchant_pack_id', 'status', 'quantity', 'payer_pernum', 'merchant_pernum', 'outlet_label',
                'confirmation_mode', 'payer_transfer_reference', 'declared_at', 'confirmed_at', 'confirmed_by', 'closed_at', 'created_at'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->lazy()
            ->map(fn (object $row): array => (array) $row);
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function merchantBalances(): iterable
    {
        return DB::table('merchant_accounts as ma')
            ->join('user_packs as up', 'up.id', '=', 'ma.user_pack_id')
            ->select(['ma.user_pack_id', 'up.user_id', 'ma.eligible', 'ma.reserved', 'ma.redeemed', 'ma.entries_count'])
            ->orderBy('ma.user_pack_id')
            ->lazy()
            ->map(fn (object $row): array => (array) $row);
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function payouts(): iterable
    {
        return DB::table('payout_items as i')
            ->join('payout_batches as b', 'b.id', '=', 'i.payout_batch_id')
            ->select(['b.reference as batch_reference', 'b.payable_type', 'b.payable_id', 'b.beneficiary_id', 'b.total_amount', 'b.paid_amount', 'b.remaining_amount', 'b.route', 'b.method',
                'b.status as batch_status', 'i.sequence', 'i.kind', 'i.amount', 'i.fee_amount', 'i.transfer_amount', 'i.status', 'i.provider', 'i.provider_reference', 'i.attempts', 'i.submitted_at', 'i.settled_at'])
            ->orderBy('b.created_at')
            ->orderBy('b.id')
            ->orderBy('i.sequence')
            ->lazy()
            ->map(fn (object $row): array => (array) $row);
    }

    /**
     * The authorised part of the audit trail: who did what and when, with the chain hash. No before/after values,
     * IP addresses or user agents.
     *
     * @return iterable<int, array<string, mixed>>
     */
    private function audit(): iterable
    {
        return DB::table('audit_events')
            ->select(['position', 'occurred_at', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'result', 'correlation_id', 'hash'])
            ->lazyById(1000, 'position')
            ->map(fn (object $row): array => (array) $row);
    }
}
