<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Otp\OtpService;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Session\Session;

/**
 * Opens a step-up window on the current token, or on the cookie session, after an email OTP confirmation.
 */
class ConfirmStepUp
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly SettingsService $settings,
        private readonly SecurityEventLogger $securityLogger,
    ) {}

    public const SESSION_KEY = 'step_up_until';

    /**
     * @param  PersonalAccessToken|null  $token  the bearer token, or null for a cookie session
     */
    public function handle(User $user, ?PersonalAccessToken $token, ?Session $session, string $challengeId, string $code): CarbonInterface
    {
        $challenge = $this->otp->verify($challengeId, $code, OtpPurpose::StepUp);

        if ($challenge->user_id !== $user->id) {
            throw IdentityException::otpInvalid();
        }

        $until = now()->addSeconds($this->settings->integer('otp.step_up_validity_seconds'));
        if ($token !== null) {
            $token->forceFill(['step_up_until' => $until])->save();
        } else {
            $session?->put(self::SESSION_KEY, $until->getTimestamp());
        }

        $this->securityLogger->record('auth.step_up_granted', userId: $user->id, metadata: ['token_id' => $token?->id, 'mode' => $token !== null ? 'token' : 'session']);

        return $until;
    }
}
