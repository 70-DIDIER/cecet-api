<?php

namespace App\Domain\Settings;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\SensitiveDataRedactor;
use App\Domain\Settings\Exceptions\SettingException;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\Models\SettingVersion;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Reads and versions configurable settings.
 *
 * A value is never overwritten: each change creates an immutable version with an effective date,
 * so that any past decision can be reproduced with the configuration that was in force at the time.
 */
class SettingsService
{
    private const CACHE_PREFIX = 'settings:versions:';

    public function __construct(
        private readonly SettingsCatalog $catalog,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Returns the value of a setting effective at the given instant (now by default).
     */
    public function get(string $key, ?DateTimeInterface $at = null): mixed
    {
        $definition = $this->definition($key);
        $instant = CarbonImmutable::instance($at ?? now());

        $version = collect($this->cachedVersions($key))
            ->first(fn (array $version) => CarbonImmutable::parse($version['effective_from'])->lessThanOrEqualTo($instant));

        if ($version === null) {
            throw SettingException::notConfigured($key);
        }

        $value = $definition->type->normalize($version['value']);

        if (app()->isProduction() && $definition->isForbiddenInProduction($value)) {
            if ($definition->isForbiddenInProduction($definition->default)) {
                throw SettingException::notConfigured($key);
            }

            return $definition->default;
        }

        return $value;
    }

    public function integer(string $key, ?DateTimeInterface $at = null): int
    {
        $value = $this->get($key, $at);

        return is_int($value) ? $value : throw SettingException::notConfigured($key);
    }

    public function boolean(string $key, ?DateTimeInterface $at = null): bool
    {
        $value = $this->get($key, $at);

        return is_bool($value) ? $value : throw SettingException::notConfigured($key);
    }

    public function string(string $key, ?DateTimeInterface $at = null): ?string
    {
        $value = $this->get($key, $at);

        return $value === null || is_string($value) ? $value : throw SettingException::notConfigured($key);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(string $key, ?DateTimeInterface $at = null): array
    {
        $value = $this->get($key, $at);

        return is_array($value) ? $value : throw SettingException::notConfigured($key);
    }

    /**
     * Creates a new version of a setting. The previous versions are kept untouched.
     */
    public function set(
        string $key,
        mixed $value,
        string $reason,
        ?DateTimeInterface $effectiveFrom = null,
        ?Authenticatable $actor = null,
    ): SettingVersion {
        $definition = $this->definition($key);

        $validator = Validator::make(
            ['value' => $value, 'reason' => $reason],
            [...$definition->validationRules(), 'reason' => ['required', 'string', 'min:5', 'max:1000']],
        );

        if ($validator->fails()) {
            throw SettingException::invalidValue($key, $validator->errors());
        }

        $normalizedValue = $definition->type->normalize($value);

        if (app()->isProduction() && $definition->isForbiddenInProduction($normalizedValue)) {
            throw SettingException::forbiddenInProduction($key);
        }

        $now = CarbonImmutable::now();
        $effectiveFrom = $effectiveFrom !== null ? CarbonImmutable::instance($effectiveFrom) : $now;

        if ($effectiveFrom->lessThan($now->subSecond())) {
            throw SettingException::retroactive($key);
        }

        return DB::transaction(function () use ($definition, $normalizedValue, $reason, $effectiveFrom, $now, $actor): SettingVersion {
            $setting = Setting::query()->lockForUpdate()->find($definition->key)
                ?? throw SettingException::notConfigured($definition->key);

            $previousValue = $this->currentStoredValue($definition->key);

            try {
                $version = SettingVersion::query()->create([
                    'setting_key' => $definition->key,
                    'value' => $normalizedValue,
                    'effective_from' => $effectiveFrom,
                    'created_by' => $actor?->getAuthIdentifier(),
                    'reason' => trim($reason),
                    'created_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw SettingException::duplicateEffectiveDate($definition->key);
            }

            $this->auditLogger->record(
                action: 'settings.version_created',
                subject: $setting,
                before: ['value' => $this->auditable($definition, $previousValue)],
                after: [
                    'value' => $this->auditable($definition, $normalizedValue),
                    'effective_from' => $effectiveFrom->toIso8601ZuluString('microsecond'),
                    'version_id' => $version->id,
                ],
                reason: trim($reason),
                actor: $actor,
            );

            DB::afterCommit(fn () => $this->forget($definition->key));

            return $version;
        });
    }

    /**
     * Full history of a setting, most recent effective date first.
     *
     * @return Collection<int, SettingVersion>
     */
    public function versions(string $key): Collection
    {
        $this->definition($key);

        return SettingVersion::query()
            ->where('setting_key', $key)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    public function definition(string $key): SettingDefinition
    {
        return $this->catalog->find($key) ?? throw SettingException::unknown($key);
    }

    public function forget(string $key): void
    {
        Cache::forget(self::CACHE_PREFIX.$key);
    }

    /**
     * @return list<array{value: mixed, effective_from: string}>
     */
    private function cachedVersions(string $key): array
    {
        return Cache::rememberForever(self::CACHE_PREFIX.$key, fn (): array => array_values(SettingVersion::query()
            ->where('setting_key', $key)
            ->orderByDesc('effective_from')
            ->get(['value', 'effective_from'])
            ->map(fn (SettingVersion $version): array => [
                'value' => $version->value,
                'effective_from' => $version->effective_from->toIso8601ZuluString('microsecond'),
            ])
            ->all()));
    }

    private function currentStoredValue(string $key): mixed
    {
        return SettingVersion::query()
            ->where('setting_key', $key)
            ->where('effective_from', '<=', now()->format('Y-m-d H:i:s.uP'))
            ->orderByDesc('effective_from')
            ->first()
            ?->value;
    }

    private function auditable(SettingDefinition $definition, mixed $value): mixed
    {
        return $definition->isSensitive ? SensitiveDataRedactor::MASK : $value;
    }
}
