<?php

use App\Domain\Access\PermissionCatalog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('reports missing pre-presentation resources without altering any member or financial record', function () {
    seedCatalogs();
    User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    config(['superadmin.enabled' => true, 'staff_guardian.enabled' => false]);

    $before = [
        'users' => DB::table('users')->count(),
        'pack_plans' => DB::table('pack_plans')->count(),
        'payment_intents' => DB::table('payment_intents')->count(),
        'audit_events' => DB::table('audit_events')->count(),
    ];

    expect(Artisan::call('cect:demo-check', ['--json' => true]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['ready'])->toBeFalse()
        ->and($report['staff_guardian_enabled'])->toBeFalse()
        ->and($report['superadmins'])->toBe(1)
        ->and($report['issues'])->not->toBeEmpty()
        ->and(DB::table('users')->count())->toBe($before['users'])
        ->and(DB::table('pack_plans')->count())->toBe($before['pack_plans'])
        ->and(DB::table('payment_intents')->count())->toBe($before['payment_intents'])
        ->and(DB::table('audit_events')->count())->toBe($before['audit_events']);
});
