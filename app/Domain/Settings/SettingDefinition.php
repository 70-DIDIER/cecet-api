<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Enums\SettingEditLevel;
use App\Domain\Settings\Enums\SettingType;

/**
 * Code-side declaration of a configurable setting: its type, validation, default and governance.
 */
final readonly class SettingDefinition
{
    /**
     * @param  array<string, list<string>>  $rules  Laravel validation rules keyed by "value" or "value.*.field"
     * @param  list<mixed>  $productionForbiddenValues  values that can never be effective in production
     */
    public function __construct(
        public string $key,
        public string $group,
        public SettingType $type,
        public mixed $default,
        public string $description,
        public SettingEditLevel $editLevel = SettingEditLevel::Admin,
        public array $rules = [],
        public bool $isSensitive = false,
        public array $productionForbiddenValues = [],
    ) {}

    /**
     * Full validation rules, including the type's base rules on the root value.
     *
     * @return array<string, list<string>>
     */
    public function validationRules(): array
    {
        $rules = $this->rules;
        $rules['value'] = array_values(array_unique([
            ...($rules['value'] ?? ['required']),
            ...$this->type->baseRules(),
        ]));

        return $rules;
    }

    public function isForbiddenInProduction(mixed $value): bool
    {
        return in_array($value, $this->productionForbiddenValues, true);
    }
}
