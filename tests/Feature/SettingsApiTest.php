<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
});

function putSetting(string $token, string $key, mixed $value, array $extra = []): TestResponse
{
    return test()->withToken($token)->putJson("/api/v1/admin/settings/{$key}", [
        'value' => $value,
        'reason' => 'Décision du conseil d\'administration',
        ...$extra,
    ], ['Idempotency-Key' => (string) Str::ulid()]);
}

it('lists settings with their definition and effective value', function () {
    $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();

    $this->withToken(tokenFor($admin))->getJson('/api/v1/admin/settings?group=rates')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonFragment(['key' => 'rates.purchase_xof_per_yem', 'current_value' => 510, 'edit_level' => 'superadmin']);
});

it('reserves membership fee versioning to SuperAdmin', function () {
    $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    putSetting(tokenFor($admin, withStepUp: true), 'membership.fee.natural_person', 30000)->assertForbidden();

    putSetting(tokenFor($superadmin, withStepUp: true), 'membership.fee.natural_person', 30000)
        ->assertOk()
        ->assertJsonPath('data.current_value', 30000);

    $this->withToken(tokenFor($admin))->getJson('/api/v1/admin/settings/membership.fee.natural_person')
        ->assertOk()
        ->assertJsonCount(2, 'data.versions')
        ->assertJsonPath('data.versions.0.created_by', $superadmin->id);
});

it('allows SuperAdmin to schedule a future rate without changing the current value', function () {
    $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();

    putSetting(tokenFor($superadmin, withStepUp: true), 'rates.sale_xof_per_yem', 700, ['effective_from' => now()->addMonth()->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('data.current_value', 650);
});

it('reserves critical settings to the Superadmin', function () {
    $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();

    putSetting(tokenFor($admin, withStepUp: true), 'payout.auto_enabled', true)->assertForbidden();
    putSetting(tokenFor($superadmin, withStepUp: true), 'payout.auto_enabled', true)->assertOk();

    expect(app(SettingsService::class)->get('payout.auto_enabled'))->toBeTrue();
});

it('returns domain validation errors for an authorized SuperAdmin', function () {
    $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();

    putSetting(tokenFor($superadmin, withStepUp: true), 'membership.fee.natural_person', -5)
        ->assertStatus(422)
        ->assertJsonPath('code', 'SETTING_VALUE_INVALID')
        ->assertJsonStructure(['details' => ['fields' => ['value']]]);
});

it('denies settings to roles without the permission', function () {
    $validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();

    $this->withToken(tokenFor($validator))->getJson('/api/v1/admin/settings')->assertForbidden();
    putSetting(tokenFor($validator, withStepUp: true), 'membership.fee.natural_person', 1)->assertForbidden();
});
