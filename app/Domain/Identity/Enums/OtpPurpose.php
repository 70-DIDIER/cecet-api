<?php

namespace App\Domain\Identity\Enums;

enum OtpPurpose: string
{
    case EmailVerification = 'email_verification';
    case Login = 'login';
    case StepUp = 'step_up';
    case AccountActivation = 'account_activation';
    case PasswordReset = 'password_reset';

    public function subject(): string
    {
        return match ($this) {
            self::EmailVerification => 'Vérification de votre adresse email',
            self::Login => 'Votre code de connexion',
            self::StepUp => 'Confirmation d\'une action sensible',
            self::AccountActivation => 'Activation de votre compte CECT',
            self::PasswordReset => 'Réinitialisation de votre mot de passe',
        };
    }
}
