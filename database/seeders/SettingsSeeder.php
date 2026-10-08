<?php

namespace Database\Seeders;

use App\Domain\Settings\Actions\SyncSettingsCatalog;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Mirror the settings catalog and create the initial version of each unconfigured setting.
     */
    public function run(SyncSettingsCatalog $sync): void
    {
        $sync->handle();
    }
}
