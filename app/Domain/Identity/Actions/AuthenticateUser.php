<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Enums\PernumStatus;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Otp\OtpService;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * First login step: checks credentials with brute-force protection, then either lets the account in
 * or sends an email OTP (always for staff, for members when configured).
 */
class AuthenticateUser
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly OtpService $otp,
        private readonly SecurityEventLogger $securityLogger,
        private readonly Request $request,
    ) {}

    /**
     * @param  string  $identifier  e-mail address or Pernum (members only)
     */
    public function handle(string $identifier, string $password): LoginResult
    {
        $identifier = mb_strtolower(trim($identifier));
        $throttleKey = 'login:'.hash('sha256', $identifier.'|'.$this->request->ip());

        if ($this->loginLockoutEnabled()
            && RateLimiter::tooManyAttempts($throttleKey, $this->settings->integer('auth.login_max_attempts'))) {
            $this->securityLogger->record('auth.login_locked', SecuritySeverity::Warning, identifier: $identifier);

            throw IdentityException::tooManyLoginAttempts(RateLimiter::availableIn($throttleKey));
        }

        $user = $this->findByIdentifier($identifier);

        $passwordMatches = $this->passwordMatches($user, $password);

        if ($user === null || ! $passwordMatches) {
            if ($this->loginLockoutEnabled()) {
                RateLimiter::hit($throttleKey, $this->settings->integer('auth.login_lockout_seconds'));
            }

            $this->securityLogger->record('auth.login_failed', userId: $user?->id, identifier: $identifier);

            throw IdentityException::invalidCredentials();
        }

        // Password + legacy OTP must never bypass the isolated mTLS + access-key workflow.
        if ($user->isSuperadmin() && config('superadmin.enabled')) {
            throw IdentityException::invalidCredentials();
        }

        if ($user->isStaff() && ! $user->isSuperadmin() && config('staff_guardian.enabled')) {
            throw IdentityException::invalidCredentials();
        }

        RateLimiter::clear($throttleKey);

        $this->ensureCanAuthenticate($user);

        if ($user->isStaff() || $this->settings->boolean('otp.required_on_member_login')) {
            return LoginResult::otpRequired($user, $this->otp->issue($user, OtpPurpose::Login));
        }

        return LoginResult::authenticated($user);
    }

    /**
     * An e-mail address, or the Pernum of an active identity (the login of the former site).
     */
    private function findByIdentifier(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return User::query()->where('email', $identifier)->first();
        }

        return User::query()
            ->where('kind', UserKind::Member)
            ->whereHas('pernumIdentities', fn ($query) => $query
                ->whereRaw('lower(pernum) = ?', [$identifier])
                ->where('status', '<>', PernumStatus::Revoked))
            ->first();
    }

    private function passwordMatches(?User $user, string $password): bool
    {
        if ($user === null || $user->password === null) {
            // Equalizes response time whether or not the account exists.
            Hash::check($password, Hash::make('timing-equalizer'));

            return false;
        }

        return Hash::check($password, $user->password);
    }


    /**
     * Lockouts are deliberately disabled outside production so test/staging accounts
     * cannot block operators during functional recette. Production keeps brute-force
     * protection regardless of staging behaviour.
     */
    private function loginLockoutEnabled(): bool
    {
        return app()->environment('production');
    }

    private function ensureCanAuthenticate(User $user): void
    {
        match ($user->status) {
            UserStatus::Suspended, UserStatus::Closed => throw IdentityException::accountDisabled(),
            UserStatus::PendingActivation => throw IdentityException::accountNotActivated(),
            UserStatus::PendingEmailVerification => throw IdentityException::emailNotVerified(
                $this->otp->issue($user, OtpPurpose::EmailVerification)->id,
            ),
            default => null,
        };
    }
}
