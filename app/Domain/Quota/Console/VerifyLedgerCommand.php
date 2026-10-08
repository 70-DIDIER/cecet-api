<?php

namespace App\Domain\Quota\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes every quota account and every merchant account from its ledger entries and reports any divergence.
 * A divergence is a P1 incident: payouts must be frozen until it is explained.
 */
class VerifyLedgerCommand extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Vérifie que chaque compte de quota et chaque compte marchand correspond exactement à la somme de ses écritures';

    public function handle(): int
    {
        $quota = DB::select(<<<'SQL'
            SELECT a.user_pack_id,
                   a.available, coalesce(sum(e.available_delta), 0) AS ledger_available,
                   a.reserved,  coalesce(sum(e.reserved_delta), 0)  AS ledger_reserved,
                   a.consumed,  coalesce(sum(e.consumed_delta), 0)  AS ledger_consumed
            FROM quota_accounts a
            LEFT JOIN quota_ledger_entries e ON e.user_pack_id = a.user_pack_id
            GROUP BY a.user_pack_id, a.available, a.reserved, a.consumed
            HAVING a.available <> coalesce(sum(e.available_delta), 0)
                OR a.reserved  <> coalesce(sum(e.reserved_delta), 0)
                OR a.consumed  <> coalesce(sum(e.consumed_delta), 0)
        SQL);

        $merchant = DB::select(<<<'SQL'
            SELECT a.user_pack_id,
                   a.eligible, coalesce(sum(e.eligible_delta), 0) AS ledger_eligible,
                   a.reserved, coalesce(sum(e.reserved_delta), 0) AS ledger_reserved,
                   a.redeemed, coalesce(sum(e.redeemed_delta), 0) AS ledger_redeemed
            FROM merchant_accounts a
            LEFT JOIN merchant_ledger_entries e ON e.user_pack_id = a.user_pack_id
            GROUP BY a.user_pack_id, a.eligible, a.reserved, a.redeemed
            HAVING a.eligible <> coalesce(sum(e.eligible_delta), 0)
                OR a.reserved <> coalesce(sum(e.reserved_delta), 0)
                OR a.redeemed <> coalesce(sum(e.redeemed_delta), 0)
        SQL);

        foreach ($quota as $row) {
            $this->error("Divergence sur le pack {$row->user_pack_id} : compte (disponible {$row->available}, réservé {$row->reserved}, consommé {$row->consumed}) ≠ journal ({$row->ledger_available}, {$row->ledger_reserved}, {$row->ledger_consumed}).");
        }

        foreach ($merchant as $row) {
            $this->error("Divergence sur le compte marchand {$row->user_pack_id} : compte (éligible {$row->eligible}, réservé {$row->reserved}, rééchangé {$row->redeemed}) ≠ journal ({$row->ledger_eligible}, {$row->ledger_reserved}, {$row->ledger_redeemed}).");
        }

        if ($quota !== [] || $merchant !== []) {
            return self::FAILURE;
        }

        $checked = (int) DB::table('quota_accounts')->count();
        $checkedMerchant = (int) DB::table('merchant_accounts')->count();

        $this->info("Journaux cohérents ({$checked} compte(s) de quota, {$checkedMerchant} compte(s) marchand vérifié(s)).");

        return self::SUCCESS;
    }
}
