<?php

namespace App\Domain\Exchange\Exceptions;

use App\Domain\Shared\Exceptions\ApiException;

final class ExchangeException extends ApiException
{
    public static function cectPernumNotConfigured(): self
    {
        return new self('CECT_PERNUM_NOT_CONFIGURED', 'Le Pernum de réception de la CECT n\'est pas configuré : les ventes sont momentanément indisponibles.', 503);
    }

    public static function pernumRequired(): self
    {
        return new self('PERNUM_REQUIRED', 'Aucun Pernum actif n\'est associé à votre compte.', 422);
    }

    public static function packNotEligible(): self
    {
        return new self('PACK_NOT_ELIGIBLE_FOR_EXCHANGE', 'Seul un pack Vendeur permet de vendre des YEM à la CECT.', 422);
    }

    public static function notOpen(string $status): self
    {
        return new self('EXCHANGE_NOT_OPEN', 'Cette demande n\'est plus en attente de décision.', 409, ['status' => $status]);
    }

    public static function notCancelable(string $status): self
    {
        return new self('EXCHANGE_NOT_CANCELABLE', 'Une demande ne peut être annulée qu\'avant la déclaration du transfert.', 409, ['status' => $status]);
    }

    public static function receivedExceedsRequested(string $requested): self
    {
        return new self('RECEIVED_EXCEEDS_REQUESTED', 'La quantité reçue dépasse la quantité demandée : placez la demande en litige.', 422, ['quantity_requested' => $requested]);
    }

    public static function externalReferenceRequired(): self
    {
        return new self('EXTERNAL_REFERENCE_REQUIRED', 'La référence de la transaction YEM est obligatoire pour confirmer une réception.', 422);
    }
}
