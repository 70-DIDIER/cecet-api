<?php

namespace App\Domain\Identity\Otp;

use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Issues and verifies single-use, purpose-bound codes delivered by email.
 *
 * Codes are 6 digits from a CSPRNG, stored as an HMAC bound to the challenge, compared in constant time,
 * limited in attempts and validity, and throttled per account (cooldown and hourly cap).
 */
class OtpService
{
    private const CODE_DIGITS = 6;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly OtpChannel $channel,
        private readonly SecurityEventLogger $securityLogger,
        private readonly Request $request,
    ) {}

    public function issue(User $user, OtpPurpose $purpose): OtpChallenge
    {
        $this->ensureSendingAllowed($user, $purpose);

        $code = str_pad((string) random_int(0, 10 ** self::CODE_DIGITS - 1), self::CODE_DIGITS, '0', STR_PAD_LEFT);
        $ttlSeconds = $purpose === OtpPurpose::AccountActivation
            ? $this->settings->integer('otp.activation_ttl_seconds')
            : $this->settings->integer('otp.ttl_seconds');

        $challenge = DB::transaction(function () use ($user, $purpose, $code, $ttlSeconds): OtpChallenge {
            OtpChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);

            $challenge = new OtpChallenge([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'code_hash' => str_repeat('0', 64),
                'sent_to' => $user->email,
                'max_attempts' => $this->settings->integer('otp.max_attempts'),
                'expires_at' => now()->addSeconds($ttlSeconds),
                'ip_address' => $this->request->ip(),
            ]);
            $challenge->id = $challenge->newUniqueId();
            $challenge->code_hash = $this->hash($challenge->id, $code);
            $challenge->save();

            $this->channel->send($challenge, $code);

            return $challenge;
        });

        $this->securityLogger->record('otp.issued', userId: $user->id, metadata: ['purpose' => $purpose->value, 'challenge_id' => $challenge->id]);

        return $challenge;
    }

    /**
     * Verifies a code against a known challenge and consumes it on success.
     */
    public function verify(string $challengeId, string $code, OtpPurpose $purpose): OtpChallenge
    {
        $outcome = DB::transaction(function () use ($challengeId, $code, $purpose): array {
            $challenge = OtpChallenge::query()
                ->whereKey($challengeId)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            return $this->attempt($challenge, $code);
        });

        return $this->resolve($outcome, $purpose);
    }

    /**
     * Verifies a code against the latest usable challenge of a user for the given purpose.
     */
    public function verifyLatest(User $user, string $code, OtpPurpose $purpose): OtpChallenge
    {
        $outcome = DB::transaction(function () use ($user, $code, $purpose): array {
            $challenge = OtpChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('superseded_at')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            return $this->attempt($challenge, $code);
        });

        return $this->resolve($outcome, $purpose);
    }

    /**
     * Records the attempt inside the transaction; the outcome is raised after commit
     * so that failed attempts are persisted.
     *
     * @return array{result: 'valid'|'invalid'|'locked', challenge: OtpChallenge|null}
     */
    private function attempt(?OtpChallenge $challenge, string $code): array
    {
        if ($challenge === null || ! $challenge->isUsable()) {
            return ['result' => 'invalid', 'challenge' => $challenge];
        }

        if ($challenge->isLocked()) {
            return ['result' => 'locked', 'challenge' => $challenge];
        }

        if (! hash_equals($challenge->code_hash, $this->hash($challenge->id, $code))) {
            $challenge->increment('attempts');

            return ['result' => $challenge->isLocked() ? 'locked' : 'invalid', 'challenge' => $challenge];
        }

        $challenge->forceFill(['consumed_at' => now()])->save();

        return ['result' => 'valid', 'challenge' => $challenge];
    }

    /**
     * @param  array{result: 'valid'|'invalid'|'locked', challenge: OtpChallenge|null}  $outcome
     */
    private function resolve(array $outcome, OtpPurpose $purpose): OtpChallenge
    {
        $challenge = $outcome['challenge'];

        if ($outcome['result'] === 'valid' && $challenge !== null) {
            $this->securityLogger->record('otp.verified', userId: $challenge->user_id, metadata: ['purpose' => $purpose->value]);

            return $challenge;
        }

        $this->securityLogger->record(
            $outcome['result'] === 'locked' ? 'otp.locked' : 'otp.failed',
            $outcome['result'] === 'locked' ? SecuritySeverity::Warning : SecuritySeverity::Info,
            userId: $challenge?->user_id,
            metadata: ['purpose' => $purpose->value, 'challenge_id' => $challenge?->id],
        );

        throw $outcome['result'] === 'locked' ? IdentityException::otpLocked() : IdentityException::otpInvalid();
    }

    private function ensureSendingAllowed(User $user, OtpPurpose $purpose): void
    {
        $latest = OtpChallenge::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->latest()
            ->first();

        $cooldown = $this->settings->integer('otp.resend_cooldown_seconds');

        if ($latest !== null && $latest->created_at->diffInSeconds(now()) < $cooldown) {
            throw IdentityException::otpResendTooSoon((int) ceil($cooldown - $latest->created_at->diffInSeconds(now())));
        }

        $sentLastHour = OtpChallenge::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($sentLastHour >= $this->settings->integer('otp.max_sends_per_hour')) {
            $this->securityLogger->record('otp.send_limit_reached', SecuritySeverity::Warning, userId: $user->id);

            throw IdentityException::otpSendLimitReached();
        }
    }

    private function hash(string $challengeId, string $code): string
    {
        return hash_hmac('sha256', $challengeId.'|'.$code, (string) config('app.key'));
    }
}
