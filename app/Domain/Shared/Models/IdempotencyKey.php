<?php

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Stored outcome of a mutating request, replayed when the same Idempotency-Key is reused.
 *
 * @property string $id
 * @property string $scope
 * @property string $key
 * @property string $request_fingerprint
 * @property string $status
 * @property int|null $response_status
 * @property string|null $response_body
 * @property string|null $response_content_type
 * @property Carbon $expires_at
 */
#[Fillable(['scope', 'key', 'request_fingerprint', 'status', 'response_status', 'response_body', 'response_content_type', 'expires_at'])]
class IdempotencyKey extends Model
{
    use HasUlids;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
