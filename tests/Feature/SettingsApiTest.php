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
        ->assertJsonCount(4, 'data')
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

it('lets SuperAdmin version merchant redemption policy but never delegates it to an ordinary Administrator', function () {
    $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    $policy = [
        'mode' => 'sale_minus_fee', 'fee_bps' => 300,
        'fixed_fee_xof' => 100, 'fee_rounding' => 'up',
    ];

    putSetting(tokenFor($admin, withStepUp: true), 'merchant_redemption.pricing', $policy)
        ->assertForbidden();

    putSetting(tokenFor($superadmin, withStepUp: true), 'merchant_redemption.pricing', $policy)
        ->assertOk()
        ->assertJsonPath('data.current_value.mode', 'sale_minus_fee')
        ->assertJsonPath('data.current_value.fee_bps', 300);

    $this->withToken(tokenFor($superadmin))->getJson('/api/v1/admin/settings/merchant_redemption.pricing')
        ->assertOk()
        ->assertJsonPath('data.versions.0.created_by', $superadmin->id)
        ->assertJsonCount(2, 'data.versions');
});

it('rejects arbitrary merchant policy structures, excessive fee rates and negative costs', function () {
    $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    $token = tokenFor($superadmin, withStepUp: true);

    foreach ([
        ['mode' => 'unknown', 'fee_bps' => 300, 'fixed_fee_xof' => 0, 'fee_rounding' => 'down'],
        ['mode' => 'fixed', 'fee_bps' => 10000, 'fixed_fee_xof' => 0, 'fee_rounding' => 'down'],
        ['mode' => 'sale_minus_fee', 'fee_bps' => 300, 'fixed_fee_xof' => -1, 'fee_rounding' => 'down'],
        ['mode' => 'sale_minus_fee', 'fee_bps' => 300, 'fixed_fee_xof' => 0, 'fee_rounding' => 'bankers'],
        ['mode' => 'sale_minus_fee', 'fee_bps' => 300, 'fixed_fee_xof' => 0, 'fee_rounding' => 'down', 'arbitrary_override' => true],
    ] as $policy) {
        putSetting($token, 'merchant_redemption.pricing', $policy)
            ->assertStatus(422)
            ->assertJsonPath('code', 'SETTING_VALUE_INVALID');
    }
});
