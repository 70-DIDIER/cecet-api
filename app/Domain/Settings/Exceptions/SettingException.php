<?php

namespace App\Domain\Settings\Exceptions;

use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Support\MessageBag;

final class SettingException extends ApiException
{
    public static function unknown(string $key): self
    {
        return new self('SETTING_NOT_FOUND', "Paramètre inconnu : {$key}.", 404, ['key' => $key]);
    }

    public static function notConfigured(string $key): self
    {
        return new self('SETTING_NOT_CONFIGURED', "Le paramètre {$key} n'a aucune valeur effective.", 503, ['key' => $key]);
    }

    public static function invalidValue(string $key, MessageBag $errors): self
    {
        return new self('SETTING_VALUE_INVALID', "Valeur invalide pour le paramètre {$key}.", 422, [
            'key' => $key,
            'fields' => $errors->toArray(),
        ]);
    }

    public static function forbiddenInProduction(string $key): self
    {
        return new self('SETTING_VALUE_FORBIDDEN_IN_PRODUCTION', "Cette valeur du paramètre {$key} est interdite en production.", 422, ['key' => $key]);
    }

    public static function retroactive(string $key): self
    {
        return new self('SETTING_RETROACTIVE_CHANGE', "Une nouvelle version de {$key} ne peut pas prendre effet dans le passé.", 422, ['key' => $key]);
    }

    public static function duplicateEffectiveDate(string $key): self
    {
        return new self('SETTING_VERSION_CONFLICT', "Une version de {$key} existe déjà à cette date d'effet.", 409, ['key' => $key]);
    }
}
