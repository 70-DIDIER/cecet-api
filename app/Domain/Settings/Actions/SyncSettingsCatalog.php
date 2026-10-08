<?php

namespace App\Domain\Settings\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\SensitiveDataRedactor;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\Models\SettingVersion;
use App\Domain\Settings\SettingDefinition;
use App\Domain\Settings\SettingsCatalog;
use App\Domain\Settings\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the code catalog into the database and creates the initial version of any setting
 * that has never been configured. Existing values are never modified.
 */
class SyncSettingsCatalog
{
    public const INITIAL_VERSION_REASON = 'Valeur initiale issue du catalogue applicatif.';

    public function __construct(
        private readonly SettingsCatalog $catalog,
        private readonly SettingsService $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{definitions: int, initialized: list<string>, orphaned: list<string>}
     */
    public function handle(): array
    {
        $definitions = $this->catalog->all();
        $initialized = [];

        DB::transaction(function () use ($definitions, &$initialized): void {
            foreach ($definitions as $definition) {
                $setting = Setting::query()->updateOrCreate(['key' => $definition->key], $this->attributes($definition));

                if ($setting->versions()->exists()) {
                    continue;
                }

                $version = SettingVersion::query()->create([
                    'setting_key' => $definition->key,
                    'value' => $definition->default,
                    'effective_from' => now(),
                    'created_by' => null,
                    'reason' => self::INITIAL_VERSION_REASON,
                    'created_at' => now(),
                ]);

                $this->auditLogger->record(
                    action: 'settings.initialized',
                    subject: $setting,
                    after: ['value' => $definition->isSensitive ? SensitiveDataRedactor::MASK : $definition->default, 'version_id' => $version->id],
                    reason: self::INITIAL_VERSION_REASON,
                );

                $initialized[] = $definition->key;
            }
        });

        foreach ($initialized as $key) {
            $this->settings->forget($key);
        }

        $orphaned = array_values(array_diff(
            Setting::query()->orderBy('key')->pluck('key')->map(fn (mixed $key): string => (string) $key)->all(),
            array_keys($definitions),
        ));

        return [
            'definitions' => count($definitions),
            'initialized' => $initialized,
            'orphaned' => $orphaned,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(SettingDefinition $definition): array
    {
        return [
            'group' => $definition->group,
            'type' => $definition->type,
            'description' => $definition->description,
            'rules' => $definition->validationRules(),
            'default_value' => $definition->default,
            'edit_level' => $definition->editLevel,
            'is_sensitive' => $definition->isSensitive,
            'production_forbidden_values' => $definition->productionForbiddenValues === [] ? null : $definition->productionForbiddenValues,
        ];
    }
}
