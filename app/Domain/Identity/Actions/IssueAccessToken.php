<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues an expiring API token whose lifetime depends on the account kind.
 */
class IssueAccessToken
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SecurityEventLogger $securityLogger,
        private readonly Request $request,
    ) {}

    public function handle(User $user, string $deviceName): NewAccessToken
    {
        $ttlMinutes = $this->settings->integer($user->isStaff() ? 'auth.staff_token_ttl_minutes' : 'auth.member_token_ttl_minutes');

        $newToken = $user->createToken($deviceName, ['*'], now()->addMinutes($ttlMinutes));

        /** @var PersonalAccessToken $accessToken */
        $accessToken = $newToken->accessToken;
        $accessToken->forceFill([
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ])->save();

        $user->forceFill(['last_login_at' => now()])->save();

        $this->securityLogger->record('auth.login_succeeded', userId: $user->id, metadata: ['token_id' => $accessToken->id]);

        return $newToken;
    }
}
