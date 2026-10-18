<?php

namespace App\Domain\Access\Console;

use App\Domain\Access\PermissionCatalog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Resets only demonstration business data. Security/audit trails, the SuperAdmin,
 * role catalog, product packs and pricing configuration remain untouched.
 * Requires a verified offline DB backup and an explicit confirmation token.
 */
class ResetDemoActivityCommand extends Command
{
    protected $signature = 'cect:reset-demo-activity
        {--execute : Actually delete demo records in a single transaction}
        {--confirm= : Must equal RESET-CECT-DEMO when executing}
        {--backup= : Absolute path to a completed local pg_dump archive}';

    protected $description = 'Clear demo members and transactions, preserving security and master configuration';

    private const BUSINESS_TABLES = [
        'import_rows', 'import_jobs', 'export_snapshots',
        'validation_events', 'provider_webhook_events',
        'merchant_payments', 'merchant_ledger_entries', 'quota_ledger_entries',
        'payout_items', 'manual_settlements', 'exchange_requests',
        'merchant_qr_codes', 'merchant_accounts', 'merchant_directory_profiles',
        'quota_accounts', 'pack_periods', 'pack_topups', 'user_packs',
        'payout_destinations', 'payout_batches',
        'payment_attempts', 'payment_intents',
        'member_notifications', 'memberships', 'organizations', 'pernum_identities',
        'idempotency_keys',
    ];

    public function handle(): int
    {
        if (! app()->environment(['staging', 'local', 'testing'])) {
            $this->error('Refused outside a non-production environment.');
            return self::FAILURE;
        }

        $adminCount = User::query()->role(PermissionCatalog::SUPERADMIN)->count();
        $admin = User::query()->role(PermissionCatalog::SUPERADMIN)->first();
        if ($adminCount !== 1 || ! $admin || ! (bool) config('superadmin.enabled')) {
            $this->error('Refused: exactly one protected, enabled SuperAdmin must exist.');
            return self::FAILURE;
        }

        $members = User::query()->where('kind', 'member')->pluck('id')->all();
        if (in_array($admin->id, $members, true)) {
            $this->error('Refused: SuperAdmin was classified as a member.');
            return self::FAILURE;
        }

        $counts = ['members' => count($members)];
        foreach (self::BUSINESS_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $counts['member_sessions'] = count($members) ? DB::table('sessions')->whereIn('user_id', $members)->count() : 0;
        $counts['legacy_member_otps'] = count($members) ? DB::table('otp_challenges')->whereIn('user_id', $members)->count() : 0;
        $counts['security_profiles_preserved'] = DB::table('superadmin_security_profiles')->count();
        $counts['security_keys_preserved'] = DB::table('superadmin_access_keys')->count();
        $counts['packs_preserved'] = DB::table('pack_plans')->count();

        $this->line(json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (! $this->option('execute')) {
            $this->info('DRY RUN ONLY. No records deleted.');
            return self::SUCCESS;
        }

        $backup = (string) $this->option('backup');
        $root = '/home/ubuntu/cect-private-backups/';
        if ($this->option('confirm') !== 'RESET-CECT-DEMO'
            || ! str_starts_with($backup, $root)
            || ! is_file($backup)
            || filesize($backup) < 4096) {
            $this->error('Refused: confirmation token or verified local backup is missing.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($members, $admin): void {
            // Race-safe for demo traffic: no active public business transaction
            // should be initiated while resetting staging data.
            foreach (self::BUSINESS_TABLES as $table) {
                DB::table($table)->delete();
            }
            if ($members) {
                DB::table('otp_challenges')->whereIn('user_id', $members)->delete();
                DB::table('sessions')->whereIn('user_id', $members)->delete();
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)->whereIn('tokenable_id', $members)->delete();
                DB::table('model_has_permissions')
                    ->where('model_type', User::class)->whereIn('model_id', $members)->delete();
                DB::table('model_has_roles')
                    ->where('model_type', User::class)->whereIn('model_id', $members)->delete();
                User::query()->where('kind', 'member')->where('id', '<>', $admin->id)->delete();
            }
        }, 3);

        if (User::query()->role(PermissionCatalog::SUPERADMIN)->count() !== 1) {
            $this->error('ALERT: SuperAdmin invariant changed; restore from backup.');
            return self::FAILURE;
        }

        $this->info('RESET COMPLETED. Demo transactions and member accounts deleted.');
        $this->info('SECURITY, AUDIT LOGS, PACK CATALOG, PRICES, SETTINGS AND SUPERADMIN PRESERVED.');
        $this->line('Backup kept at '.$backup);
        return self::SUCCESS;
    }
}
