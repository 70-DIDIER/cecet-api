<?php

namespace App\Domain\Reporting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a staged import: its diff (base / imported / current per field) or its errors.
 *
 * @property string $id
 * @property string $import_job_id
 * @property int $line
 * @property string|null $user_id
 * @property string $status
 * @property array<string, array{base: string|null, imported: string|null, current: string|null}>|null $changes
 * @property list<string>|null $errors
 */
#[Fillable(['import_job_id', 'line', 'user_id', 'status', 'changes', 'errors'])]
class ImportRow extends Model
{
    use HasUlids;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'changes' => 'array',
            'errors' => 'array',
        ];
    }
}
