<?php

namespace App\Domain\Identity;

use App\Domain\Settings\SettingsService;
use Illuminate\Validation\Rules\Password;

/**
 * Password rules driven by configuration (minimum length) with fixed complexity requirements.
 */
class PasswordPolicy
{
    public function __construct(private readonly SettingsService $settings) {}

    public function rule(): Password
    {
        return Password::min($this->settings->integer('auth.password_min_length'))
            ->max(128)
            ->letters()
            ->mixedCase()
            ->numbers();
    }
}
