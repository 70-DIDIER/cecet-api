<?php

namespace App\Domain\Quota\Exceptions;

use App\Domain\Shared\Exceptions\ApiException;

final class QuotaException extends ApiException
{
    public static function insufficientAvailable(string $available, string $requested): self
    {
        return new self('INSUFFICIENT_QUOTA', 'Quota disponible insuffisant.', 422, ['available' => $available, 'requested' => $requested]);
    }

    public static function insufficientReserved(string $reserved, string $requested): self
    {
        return new self('INSUFFICIENT_RESERVED_QUOTA', 'La quantité réservée est insuffisante pour cette opération.', 422, ['reserved' => $reserved, 'requested' => $requested]);
    }

    public static function invalidQuantity(): self
    {
        return new self('INVALID_QUANTITY', 'La quantité doit être un nombre strictement positif (6 décimales au plus).', 422);
    }

    public static function packNotUsable(): self
    {
        return new self('PACK_NOT_USABLE', 'Ce pack n\'est pas actif : le quota ne peut pas être réservé.', 409);
    }

    public static function outsideOperationLimits(?string $min, ?string $max): self
    {
        return new self('OPERATION_OUT_OF_LIMITS', 'La quantité est hors des limites autorisées par ce pack.', 422, array_filter(['min' => $min, 'max' => $max]));
    }

    public static function idempotencyConflict(string $key): self
    {
        return new self('LEDGER_IDEMPOTENCY_CONFLICT', 'Cette clé d\'idempotence a déjà été utilisée pour une opération différente.', 409, ['key' => $key]);
    }

    public static function accountMissing(): self
    {
        return new self('QUOTA_ACCOUNT_MISSING', 'Ce pack n\'a pas de compte de quota.', 409);
    }
}
