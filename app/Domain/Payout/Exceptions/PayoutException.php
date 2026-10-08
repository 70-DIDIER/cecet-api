<?php

namespace App\Domain\Payout\Exceptions;

use App\Domain\Shared\Exceptions\ApiException;

final class PayoutException extends ApiException
{
    public static function notDispatchable(string $status): self
    {
        return new self('PAYOUT_NOT_DISPATCHABLE', 'Ce payout ne peut pas être envoyé dans son état actuel.', 409, ['status' => $status]);
    }

    public static function nothingToRetry(): self
    {
        return new self('PAYOUT_NOTHING_TO_RETRY', 'Aucun envoi en échec à relancer pour ce payout.', 409);
    }

    public static function transferInProgress(): self
    {
        return new self('PAYOUT_TRANSFER_IN_PROGRESS', 'Un envoi est en cours : attendez son résultat avant de régler autrement.', 409);
    }

    public static function amountMustMatchRemaining(int $remaining): self
    {
        return new self('SETTLEMENT_AMOUNT_MISMATCH', 'Le règlement manuel doit couvrir exactement le reste dû.', 422, ['remaining_amount' => $remaining]);
    }

    public static function alreadyPaid(): self
    {
        return new self('PAYOUT_ALREADY_PAID', 'Ce payout est déjà entièrement réglé.', 409);
    }

    public static function settlementPending(): self
    {
        return new self('SETTLEMENT_PENDING_APPROVAL', 'Un règlement manuel attend déjà une approbation pour ce payout.', 409);
    }

    public static function settlementNotPending(): self
    {
        return new self('SETTLEMENT_NOT_PENDING', 'Ce règlement n\'attend pas d\'approbation.', 409);
    }

    public static function sameApprover(): self
    {
        return new self('MAKER_CHECKER_VIOLATION', 'Le règlement doit être approuvé par une autre personne que celle qui l\'a saisi.', 403);
    }

    public static function feesNotConfigured(string $provider): self
    {
        return new self('PAYOUT_FEES_NOT_CONFIGURED', 'Les frais de décaissement du prestataire ne sont pas configurés.', 503, ['provider' => $provider]);
    }

    public static function unknownProvider(string $provider): self
    {
        return new self('PAYOUT_PROVIDER_UNKNOWN', 'Prestataire de décaissement inconnu.', 503, ['provider' => $provider]);
    }

    public static function destinationRequired(): self
    {
        return new self('PAYOUT_DESTINATION_REQUIRED', 'Renseignez d\'abord le moyen par lequel vous souhaitez recevoir vos paiements.', 422);
    }
}
