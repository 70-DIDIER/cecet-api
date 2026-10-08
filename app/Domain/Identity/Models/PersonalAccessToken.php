<?php

namespace App\Domain\Identity\Models;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * API token with a step-up window for sensitive actions.
 *
 * @property Carbon|null $step_up_until
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public function hasValidStepUp(): bool
    {
        return $this->step_up_until !== null && $this->step_up_until->isFuture();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step_up_until' => 'datetime',
        ];
    }
}
