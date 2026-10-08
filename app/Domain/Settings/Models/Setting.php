<?php

namespace App\Domain\Settings\Models;

use App\Domain\Settings\Enums\SettingEditLevel;
use App\Domain\Settings\Enums\SettingType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Database mirror of a catalog setting definition.
 *
 * @property string $key
 * @property string $group
 * @property SettingType $type
 * @property string $description
 * @property array<string, list<string>> $rules
 * @property mixed $default_value
 * @property SettingEditLevel $edit_level
 * @property bool $is_sensitive
 * @property list<mixed>|null $production_forbidden_values
 */
#[Fillable(['key', 'group', 'type', 'description', 'rules', 'default_value', 'edit_level', 'is_sensitive', 'production_forbidden_values'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return HasMany<SettingVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(SettingVersion::class, 'setting_key', 'key');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'rules' => 'array',
            'default_value' => 'json',
            'edit_level' => SettingEditLevel::class,
            'is_sensitive' => 'boolean',
            'production_forbidden_values' => 'array',
        ];
    }
}
