<?php

namespace Database\Seeders;

use App\Domain\Access\Actions\SyncAccessCatalog;
use Illuminate\Database\Seeder;

class AccessSeeder extends Seeder
{
    /**
     * Create catalog permissions and system roles (Superadmin, Admin, Validateur, Comptabilité).
     */
    public function run(SyncAccessCatalog $sync): void
    {
        $sync->handle();
    }
}
