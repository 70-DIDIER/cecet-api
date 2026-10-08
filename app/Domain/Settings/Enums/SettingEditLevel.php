<?php

namespace App\Domain\Settings\Enums;

/**
 * Minimum internal role allowed to create a new version of a setting.
 */
enum SettingEditLevel: string
{
    case Admin = 'admin';
    case Superadmin = 'superadmin';
}
