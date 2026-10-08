<?php

namespace App\Domain\Access\Console;

use App\Domain\Access\AccountManager;
use App\Domain\Access\Actions\SyncAccessCatalog;
use App\Domain\Access\PermissionCatalog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Bootstraps a Superadmin account. The person receives an activation code by email and chooses
 * their password through the API: no password ever transits through the command line.
 */
class CreateSuperadminCommand extends Command
{
    protected $signature = 'cect:create-superadmin
        {email : Adresse email nominative}
        {first_name : Prénom}
        {last_name : Nom}
        {--additional : Autoriser la création alors qu\'un Superadmin existe déjà}';

    protected $description = 'Crée un compte Superadmin et lui envoie un code d\'activation par email';

    public function handle(SyncAccessCatalog $syncAccess, AccountManager $accounts): int
    {
        $identity = [
            'email' => (string) $this->argument('email'),
            'first_name' => (string) $this->argument('first_name'),
            'last_name' => (string) $this->argument('last_name'),
        ];

        $validator = Validator::make($identity, [
            'email' => ['required', 'email:rfc', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $syncAccess->handle();

        if (! $this->option('additional') && User::query()->role(PermissionCatalog::SUPERADMIN)->exists()) {
            $this->error('Un Superadmin existe déjà. Utilisez --additional pour en créer un autre.');

            return self::FAILURE;
        }

        $user = $accounts->create(null, $identity, [PermissionCatalog::SUPERADMIN]);

        $this->info("Superadmin créé ({$user->email}). Un code d'activation a été envoyé par email.");

        return self::SUCCESS;
    }
}
