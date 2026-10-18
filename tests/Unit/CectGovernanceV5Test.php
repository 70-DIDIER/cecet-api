<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Settings\Enums\SettingEditLevel;
use App\Domain\Settings\SettingsCatalog;

it('keeps precisely the three internal roles of CECT governance v5', function () {
    $catalog = new PermissionCatalog();
    expect(array_keys($catalog->systemRoles()))->toBe([
        'superadmin', 'administrateur', 'comptabilite',
    ]);
    expect($catalog->isSystemRole('validateur'))->toBeFalse();
});

it('grants combined YEM validation and accounting privileges to the accounting role', function () {
    $catalog = new PermissionCatalog();
    $perms = $catalog->systemRoles()[PermissionCatalog::ACCOUNTING]['permissions'];

    expect($perms)->toContain('yem_validations.view')
        ->toContain('yem_validations.confirm')
        ->toContain('yem_validations.dispute')
        ->toContain('payments.validate_manual')
        ->toContain('payouts.settle_manual');

    expect($catalog->violatedPairs($perms))->toBe([]);
    expect($catalog->isReserved('settings.update_critical'))->toBeTrue();
});

it('restricts money rates and membership fees to the superadmin', function () {
    $catalog = new SettingsCatalog();
    foreach ([
        'rates.purchase_xof_per_yem',
        'rates.sale_xof_per_yem',
        'rates.transfer_xof_per_yem',
        'membership.fee.natural_person',
        'membership.fee.legal_person',
        'cashback.rules',
    ] as $key) {
        expect($catalog->find($key)?->editLevel)->toBe(SettingEditLevel::Superadmin);
    }
    expect($catalog->find('rates.purchase_xof_per_yem')?->default)->toBe(510);
});
