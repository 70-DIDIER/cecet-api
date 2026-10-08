<?php

namespace App\Domain\Identity\Exceptions;

use App\Domain\Shared\Exceptions\ApiException;

final class IdentityException extends ApiException
{
    public static function invalidCredentials(): self
    {
        return new self('INVALID_CREDENTIALS', 'Identifiants invalides.', 401);
    }

    public static function tooManyLoginAttempts(int $retryAfterSeconds): self
    {
        return new self('TOO_MANY_LOGIN_ATTEMPTS', 'Trop de tentatives de connexion. Réessayez plus tard.', 429, [
            'retry_after_seconds' => $retryAfterSeconds,
        ]);
    }

    public static function accountDisabled(): self
    {
        return new self('ACCOUNT_DISABLED', 'Ce compte est suspendu ou fermé.', 403);
    }

    public static function accountNotActivated(): self
    {
        return new self('ACCOUNT_NOT_ACTIVATED', 'Ce compte n\'a pas encore été activé.', 403);
    }

    public static function emailNotVerified(string $challengeId): self
    {
        return new self('EMAIL_NOT_VERIFIED', 'Votre adresse email doit être vérifiée. Un nouveau code vous a été envoyé.', 403, [
            'challenge_id' => $challengeId,
        ]);
    }

    public static function otpInvalid(): self
    {
        return new self('OTP_INVALID', 'Code invalide ou expiré.', 422);
    }

    public static function otpLocked(): self
    {
        return new self('OTP_LOCKED', 'Trop de tentatives sur ce code. Demandez un nouveau code.', 422);
    }

    public static function otpResendTooSoon(int $retryAfterSeconds): self
    {
        return new self('OTP_RESEND_TOO_SOON', 'Veuillez patienter avant de demander un nouveau code.', 429, [
            'retry_after_seconds' => $retryAfterSeconds,
        ]);
    }

    public static function otpSendLimitReached(): self
    {
        return new self('OTP_SEND_LIMIT_REACHED', 'Nombre maximal de codes envoyés atteint. Réessayez plus tard.', 429);
    }

    public static function stepUpRequired(): self
    {
        return new self('STEP_UP_REQUIRED', 'Cette action exige une confirmation par code envoyé par email.', 403);
    }

    public static function staffOnly(): self
    {
        return new self('STAFF_ONLY', 'Espace réservé aux comptes internes.', 403);
    }
}
