<?php

namespace App\Domain\Merchant\Exceptions;

use App\Domain\Shared\Exceptions\ApiException;

final class MerchantException extends ApiException
{
    public static function notAMerchant(): self
    {
        return new self('MERCHANT_PACK_REQUIRED', 'Un pack Marchand actif est nécessaire pour encaisser des YEM.', 403);
    }

    public static function businessPernumRequired(): self
    {
        return new self('MERCHANT_PERNUM_REQUIRED', 'Déclarez d\'abord le Pernum Business sur lequel vous recevez les YEM.', 422);
    }

    public static function qrNotFound(): self
    {
        return new self('MERCHANT_QR_INVALID', 'Ce QR code n\'est pas reconnu ou n\'est plus actif.', 404);
    }

    public static function merchantUnavailable(): self
    {
        return new self('MERCHANT_UNAVAILABLE', 'Ce marchand n\'accepte pas de paiement pour le moment.', 409);
    }

    public static function selfPayment(): self
    {
        return new self('MERCHANT_SELF_PAYMENT', 'Vous ne pouvez pas vous payer vous-même.', 422);
    }

    public static function packNotEligible(): self
    {
        return new self('PACK_NOT_ELIGIBLE_FOR_MERCHANT_PAYMENT', 'Ce pack ne permet pas de payer un marchand.', 422);
    }

    public static function notOpen(string $status): self
    {
        return new self('MERCHANT_PAYMENT_NOT_OPEN', 'Ce paiement n\'est plus en attente.', 409, ['status' => $status]);
    }

    public static function notCancelable(string $status): self
    {
        return new self('MERCHANT_PAYMENT_NOT_CANCELABLE', 'Un paiement ne peut être annulé qu\'avant la déclaration du transfert.', 409, ['status' => $status]);
    }

    public static function confirmationReservedToValidator(): self
    {
        return new self('MERCHANT_PAYMENT_VALIDATOR_CONFIRMATION', 'La confirmation de ce paiement est réservée aux Validateurs de la CECT.', 403);
    }

    public static function insufficientBalance(string $eligible, string $reserved): self
    {
        return new self('INSUFFICIENT_MERCHANT_BALANCE', 'Solde marchand insuffisant pour cette opération.', 422, ['eligible' => $eligible, 'reserved' => $reserved]);
    }
}
