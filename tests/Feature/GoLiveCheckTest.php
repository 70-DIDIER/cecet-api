<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Ops\GoLiveChecker;
use App\Domain\Settings\SettingsService;
use App\Models\User;

beforeEach(fn () => seedCatalogs());

function failingCodes(): array
{
    return collect(app(GoLiveChecker::class)->check())->reject(fn ($item) => $item['ok'])->pluck('code')->all();
}

it('blocks a development configuration', function () {
    expect(failingCodes())->toContain('app_env', 'inbound_provider', 'payout_provider', 'cect_pernum', 'alert_emails', 'superadmin', 'staff_roles');

    $this->artisan('ops:golive')->expectsOutputToContain('BLOQUANT')->assertFailed();
});

it('turns green for the settings and accounts it can judge', function () {
    $settings = app(SettingsService::class);
    $settings->set('yem.cect_pernum', '1000000001', 'Pernum CECT');
    $settings->set('ops.alert_emails', ['astreinte@cect.example'], 'Astreinte');
    User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
    User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();

    expect(failingCodes())->not->toContain('cect_pernum', 'alert_emails', 'superadmin', 'staff_roles', 'demo_bypass', 'hash_driver');
});

it('blocks a configured FedaPay API token without webhook validation even with the demo gateway active', function () {
    config(['services.fedapay.secret_key' => 'sandbox-only-fixture', 'services.fedapay.webhook_secret' => null]);
    expect(failingCodes())->toContain('fedapay_webhook_ready');

    config(['services.fedapay.webhook_secret' => 'sandbox-signature-fixture']);
    expect(failingCodes())->not->toContain('fedapay_webhook_ready');
});
