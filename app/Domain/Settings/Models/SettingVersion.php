<?php

namespace App\Domain\Settings\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable value of a setting, effective from a given instant until the next version.
 *
 * @property string $id
 * @property string $setting_key
 * @property mixed $value
 * @property CarbonImmutable $effective_from
 * @property string|null $created_by
 * @property string $reason
 * @property CarbonImmutable $created_at
 */
#[Fillable(['setting_key', 'value', 'effective_from', 'created_by', 'reason', 'created_at'])]
class SettingVersion extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /**
     * Microsecond precision for timestamptz(6) columns.
     */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Setting versions are append-only.'));
        static::deleting(fn () => throw new LogicException('Setting versions are append-only.'));
    }

    /**
     * @return BelongsTo<Setting, $this>
     */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_key', 'key');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'effective_from' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
