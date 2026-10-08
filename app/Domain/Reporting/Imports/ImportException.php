<?php

namespace App\Domain\Reporting\Imports;

use App\Domain\Shared\Exceptions\ApiException;

final class ImportException extends ApiException
{
    public static function baseSnapshotUnavailable(): self
    {
        return new self('IMPORT_BASE_SNAPSHOT_UNAVAILABLE', 'Le snapshot de référence est introuvable, supprimé ou altéré : exportez un fichier plus récent.', 422);
    }

    /**
     * @param  list<string>  $expected
     */
    public static function schemaMismatch(array $expected): self
    {
        return new self('IMPORT_SCHEMA_MISMATCH', 'Les colonnes du fichier ne correspondent pas à celles du snapshot de référence.', 422, ['expected_columns' => $expected]);
    }

    public static function tooManyRows(int $max): self
    {
        return new self('IMPORT_TOO_MANY_ROWS', "Le fichier dépasse {$max} lignes.", 422, ['max_rows' => $max]);
    }

    public static function invalidEncoding(): self
    {
        return new self('IMPORT_INVALID_ENCODING', 'Le fichier doit être un CSV encodé en UTF-8.', 422);
    }

    public static function alreadyApplied(): self
    {
        return new self('IMPORT_ALREADY_APPLIED', 'Ce fichier a déjà été importé.', 409);
    }

    public static function notStaged(string $status): self
    {
        return new self('IMPORT_NOT_STAGED', 'Cet import n\'est plus en attente de décision.', 409, ['status' => $status]);
    }

    public static function sameApprover(): self
    {
        return new self('IMPORT_SAME_APPROVER', 'Un import doit être approuvé par une autre personne que celle qui l\'a chargé.', 403);
    }

    /**
     * @param  array<string, int>  $summary
     */
    public static function notApplicable(array $summary): self
    {
        return new self('IMPORT_NOT_APPLICABLE', 'Cet import contient des erreurs ou des conflits, ou aucun changement : corrigez le fichier et chargez-le à nouveau.', 422, ['summary' => $summary]);
    }

    public static function staleData(int $line, string $column): self
    {
        return new self('IMPORT_STALE_DATA', 'Des données ont changé sur la plateforme depuis le chargement : rien n\'a été appliqué, chargez le fichier à nouveau.', 409, ['line' => $line, 'column' => $column]);
    }
}
