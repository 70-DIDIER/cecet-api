<?php

namespace App\Domain\Access\Console;

use App\Domain\Access\Actions\SyncAccessCatalog;
use App\Domain\Access\PermissionCatalog;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Creates ONLY the missing demonstration SuperAdmin identity.
 * It has no password and cannot use the legacy password-based login.
 * No key material or OTP codes are generated on the application server.
 */
class ProvisionDemoSuperadminCommand extends Command
{
    protected $signature = 'cect:provision-superadmin-demo {--dry-run : Only report prerequisites}';

    protected $description = 'Provision one passwordless CECT demo SuperAdmin for mTLS onboarding';

    public function handle(SyncAccessCatalog $catalog): int
    {
        if (! app()->environment(['staging', 'local', 'testing'])) {
            $this->error('Denied: this command is restricted to non-production environments.');
            return self::FAILURE;
        }

        $fingerprint = strtolower((string) config('superadmin.bootstrap_cert_sha256'));
        if (! preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            $this->error('Set SUPERADMIN_BOOTSTRAP_CERT_SHA256 (DER SHA256) before provisioning.');
            return self::FAILURE;
        }

        $email = 'superadmin-demo@cect.invalid';
        $existing = User::query()->where('email', $email)->first();
        if ($existing) {
            if (! $existing->isSuperadmin() || $existing->password !== null) {
                $this->error('Refusing to modify an existing non-demo or password-enabled account.');
                return self::FAILURE;
            }
            $this->info('Demo SuperAdmin already exists: '.$existing->id);
            $this->line('SUPERADMIN_USER_ID='.$existing->id);
            return self::SUCCESS;
        }

        if (User::query()->role(PermissionCatalog::SUPERADMIN)->exists()) {
            $this->error('A SuperAdmin already exists. Never create a second one automatically.');
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Eligible: a single passwordless demo SuperAdmin may be provisioned.');
            return self::SUCCESS;
        }

        $catalog->handle();
        $user = User::query()->create([
            'kind' => UserKind::Staff,
            'person_type' => null,
            'first_name' => 'CECT',
            'last_name' => 'SuperAdmin Démo',
            'email' => $email,
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'password' => null,
        ]);
        $user->assignRole(PermissionCatalog::SUPERADMIN);

        $this->info('Demo SuperAdmin created without any password.');
        $this->line('SUPERADMIN_USER_ID='.$user->id);
        $this->warn('Set this ID in the application environment, then recache config.');
        $this->warn('Activate the feature ONLY AFTER nginx mTLS has been proven fail-closed.');

        return self::SUCCESS;
    }
}
