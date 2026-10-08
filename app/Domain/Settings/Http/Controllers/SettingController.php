<?php

namespace App\Domain\Settings\Http\Controllers;

use App\Domain\Audit\SensitiveDataRedactor;
use App\Domain\Settings\Enums\SettingEditLevel;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\Models\SettingVersion;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SettingController
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * All settings with their definition and currently effective value.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['group' => ['nullable', 'string', 'max:60']]);

        $settings = Setting::query()
            ->when($validated['group'] ?? null, fn ($query, string $group) => $query->where('group', $group))
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->map(fn (Setting $setting): array => $this->present($setting));

        return response()->json(['data' => $settings]);
    }

    /**
     * One setting with its full version history.
     */
    public function show(string $key): JsonResponse
    {
        $setting = Setting::query()->findOrFail($key);

        return response()->json(['data' => [
            ...$this->present($setting),
            'versions' => $this->settings->versions($key)->map(fn (SettingVersion $version): array => [
                'id' => $version->id,
                'value' => $setting->is_sensitive ? SensitiveDataRedactor::MASK : $version->value,
                'effective_from' => $version->effective_from->toIso8601ZuluString('microsecond'),
                'created_by' => $version->created_by,
                'reason' => $version->reason,
                'created_at' => $version->created_at->toIso8601ZuluString(),
            ]),
        ]]);
    }

    /**
     * Create a new version of a setting, effective now or at a future date.
     *
     * Requires a recent step-up confirmation; critical settings are reserved to the Superadmin.
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $setting = Setting::query()->findOrFail($key);

        Gate::authorize($setting->edit_level === SettingEditLevel::Superadmin ? 'settings.update_critical' : 'settings.update');

        $validated = $request->validate([
            'value' => ['present'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'effective_from' => ['nullable', 'date'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $version = $this->settings->set(
            $key,
            $validated['value'],
            $validated['reason'],
            isset($validated['effective_from']) ? CarbonImmutable::parse($validated['effective_from']) : null,
            $actor,
        );

        return response()->json(['data' => [
            ...$this->present($setting->refresh()),
            'created_version' => [
                'id' => $version->id,
                'effective_from' => $version->effective_from->toIso8601ZuluString('microsecond'),
            ],
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Setting $setting): array
    {
        return [
            'key' => $setting->key,
            'group' => $setting->group,
            'type' => $setting->type->value,
            'description' => $setting->description,
            'edit_level' => $setting->edit_level->value,
            'is_sensitive' => $setting->is_sensitive,
            'rules' => $setting->rules,
            'default_value' => $setting->is_sensitive ? SensitiveDataRedactor::MASK : $setting->default_value,
            'current_value' => $setting->is_sensitive ? SensitiveDataRedactor::MASK : $this->settings->get($setting->key),
        ];
    }
}
