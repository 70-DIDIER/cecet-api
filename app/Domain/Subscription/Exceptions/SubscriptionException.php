<?php

namespace App\Domain\Subscription\Exceptions;

use App\Domain\Shared\Exceptions\ApiException;

final class SubscriptionException extends ApiException
{
    public static function memberNotActive(): self
    {
        return new self('MEMBERSHIP_REQUIRED', 'Votre adhésion doit être active pour souscrire un pack.', 403);
    }

    public static function planNotAvailable(): self
    {
        return new self('PACK_NOT_AVAILABLE', 'Ce pack n\'est pas disponible à la souscription.', 409);
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function notEligible(array $allowed): self
    {
        return new self('PACK_NOT_ELIGIBLE', 'Ce pack n\'est pas ouvert à votre type de compte.', 422, ['eligible_person_types' => $allowed]);
    }

    public static function limitReached(int $max): self
    {
        return new self('PACK_LIMIT_REACHED', "Vous avez atteint le nombre maximal de packs structurés ({$max}).", 409, ['max_structured_packs' => $max]);
    }

    public static function topupNotAvailable(): self
    {
        return new self('TOPUP_NOT_AVAILABLE', 'Seul un pack Personnel actif peut être rechargé.', 409);
    }

    public static function topupBelowMinimum(int $minimum): self
    {
        return new self('TOPUP_BELOW_MINIMUM', "Le rechargement minimum est de {$minimum} FCFA.", 422, ['min_topup_xof' => $minimum]);
    }

    public static function topupInProgress(): self
    {
        return new self('TOPUP_IN_PROGRESS', 'Un rechargement est déjà en cours de paiement ou de validation pour ce pack.', 409);
    }
}
