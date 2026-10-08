<?php

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\Exceptions\ApiException;

final class CatalogException extends ApiException
{
    public static function codeTaken(string $code): self
    {
        return new self('PACK_CODE_TAKEN', "Un pack de code {$code} existe déjà.", 409, ['code' => $code]);
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self('PACK_TRANSITION_INVALID', "Transition de statut impossible : {$from} vers {$to}.", 409, ['from' => $from, 'to' => $to]);
    }

    public static function noPublishedVersion(): self
    {
        return new self('PACK_HAS_NO_PUBLISHED_VERSION', 'Un pack ne peut être publié que s\'il possède au moins une version publiée.', 422);
    }

    public static function versionAlreadyPublished(): self
    {
        return new self('PACK_VERSION_PUBLISHED', 'Cette version est publiée : elle est figée. Créez une nouvelle version.', 409);
    }

    public static function versionOfOtherPlan(): self
    {
        return new self('PACK_VERSION_MISMATCH', 'Cette version n\'appartient pas à ce pack.', 404);
    }

    public static function planArchived(): self
    {
        return new self('PACK_ARCHIVED', 'Un pack archivé ne peut plus être modifié.', 409);
    }
}
