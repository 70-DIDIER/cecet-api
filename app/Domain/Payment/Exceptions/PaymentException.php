<?php

namespace App\Domain\Payment\Exceptions;

use App\Domain\Shared\Exceptions\ApiException;

final class PaymentException extends ApiException
{
    public static function alreadyMember(): self
    {
        return new self('MEMBERSHIP_ALREADY_ACTIVE', 'Votre adhésion est déjà active.', 409);
    }

    public static function notPayable(): self
    {
        return new self('MEMBERSHIP_NOT_PAYABLE', 'Le paiement de l\'adhésion n\'est pas possible pour ce compte.', 403);
    }

    public static function attemptInProgress(): self
    {
        return new self('PAYMENT_ATTEMPT_IN_PROGRESS', 'Une tentative de paiement est déjà en cours de création.', 409);
    }

    public static function providerUnavailable(): self
    {
        return new self('PAYMENT_PROVIDER_UNAVAILABLE', 'Le prestataire de paiement est indisponible. Réessayez dans quelques instants.', 503);
    }

    public static function feesNotConfigured(string $provider): self
    {
        return new self('PAYMENT_FEES_NOT_CONFIGURED', 'Les frais de transaction du prestataire de paiement ne sont pas configurés.', 503, ['provider' => $provider]);
    }

    public static function methodNotAvailable(string $method): self
    {
        return new self('PAYMENT_METHOD_NOT_AVAILABLE', 'Ce moyen de paiement n\'est pas disponible.', 422, ['method' => $method]);
    }

    public static function notManual(): self
    {
        return new self('PAYMENT_NOT_MANUAL', 'Ce paiement n\'est pas un paiement à valider manuellement.', 409);
    }

    public static function notAwaitingValidation(string $status): self
    {
        return new self('PAYMENT_NOT_AWAITING_VALIDATION', 'Ce paiement n\'attend plus de validation.', 409, ['status' => $status]);
    }

    public static function receivedAmountMismatch(int $expected): self
    {
        return new self('PAYMENT_AMOUNT_MISMATCH', 'Le montant reçu ne correspond pas au montant dû.', 422, ['expected_amount' => $expected]);
    }

    public static function unknownProvider(string $provider): self
    {
        return new self('PAYMENT_PROVIDER_UNKNOWN', 'Prestataire de paiement inconnu.', 404, ['provider' => $provider]);
    }

    public static function demoUnavailable(): self
    {
        return new self('DEMO_MODE_UNAVAILABLE', 'Le mode démonstration n\'est pas disponible.', 404);
    }

    public static function invalidWebhook(): self
    {
        return new self('WEBHOOK_REJECTED', 'Webhook rejeté.', 400);
    }
}
