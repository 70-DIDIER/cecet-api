<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCatalogs();
    $this->superadmin = User::factory()->staff('superadmin')->create();
    $this->accounting = User::factory()->staff('comptabilite')->create();
    $this->admin = User::factory()->staff('administrateur')->create();
});

it('gives only SuperAdmin access to read and change named staff working hours', function () {
    $url="/api/v1/admin/staff/{$this->accounting->id}/login-hours";
    $this->withToken(tokenFor($this->admin, true))->getJson($url)->assertForbidden();
    $this->withToken(tokenFor($this->accounting, true))->putJson($url, ['enabled'=>true])->assertForbidden();
    $this->withToken(tokenFor($this->superadmin, true))->getJson($url)->assertOk()
        ->assertJsonPath('data.enabled', false);
    $this->withToken(tokenFor($this->superadmin, true))->putJson($url, [
        'enabled'=>true, 'weekdays'=>[1,2,3,4,5],
        'start_time'=>'08:00','end_time'=>'17:00',
        'reason'=>'Horaires du service comptable',
    ], ['Idempotency-Key'=>(string) Str::ulid()])->assertOk()->assertJsonPath('data.start_time','08:00');
    expect(DB::table('staff_access_windows')->where('user_id',$this->accounting->id)->value('enabled'))->toBeTrue()
        ->and(AuditEvent::query()->where('action','staff.login_hours_changed')->count())->toBe(1);
});

it('refuses inverted time ranges, empty days, duplicate days and attempts on SuperAdmin hours', function () {
    $url="/api/v1/admin/staff/{$this->accounting->id}/login-hours";
    $token=tokenFor($this->superadmin, true);
    $this->withToken($token)->putJson($url, [
        'enabled'=>true,'weekdays'=>[1,1],
        'start_time'=>'18:00','end_time'=>'08:00','reason'=>'Horaires invalides',
    ], ['Idempotency-Key'=>(string) Str::ulid()])->assertStatus(422);
    expect(DB::table('staff_access_windows')->where('user_id',$this->accounting->id)->count())->toBe(0);
    $this->withToken($token)->getJson("/api/v1/admin/staff/{$this->superadmin->id}/login-hours")->assertForbidden();
});
