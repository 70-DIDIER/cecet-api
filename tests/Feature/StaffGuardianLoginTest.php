<?php

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\StaffAccessPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCatalogs();
    config(['staff_guardian.enabled' => true]);
    $superadmin = User::factory()->staff('superadmin')->create();
    $this->accountant = User::factory()->staff('comptabilite')->create(['status' => UserStatus::Active]);
    DB::table('superadmin_security_profiles')->insert([
        'user_id' => $superadmin->id, 'cert_sha256' => str_repeat('a', 64),
        'email_1' => 'guardian1@example.test', 'email_2' => 'guardian2@example.test',
        'email_3' => 'guardian3@example.test', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->delivered = new class {
        public array $messages = [];
        public function raw($content, $callback) { $this->messages[] = $content; }
    };
    Mail::swap($this->delivered);
});

it('sends the same short-lived code to all three guardian inboxes, naming the staff member and role', function () {
    $response = $this->postJson('/api/v1/auth/staff/login/start', ['email' => $this->accountant->email])->assertStatus(202);
    expect($this->delivered->messages)->toHaveCount(3);
    $codes = collect($this->delivered->messages)->map(function ($text) {
        preg_match('/Code de connexion : ([0-9]{6})/', $text, $matches);
        return $matches[1] ?? null;
    })->all();
    expect(count(array_unique($codes)))->toBe(1)
        ->and($codes[0])->toMatch('/^[0-9]{6}$/')
        ->and($this->delivered->messages[0])->toContain('Comptabilité')
        ->and($response->json('data'))->not->toHaveKey('code');
});

it('refuses invalid OTP codes and never accepts the same challenge twice', function () {
    $start = $this->postJson('/api/v1/auth/staff/login/start', ['email' => $this->accountant->email])->assertStatus(202);
    $id = $start->json('data.challenge_id');
    $this->postJson('/api/v1/auth/staff/login/verify', ['challenge_id' => $id, 'code' => '000000'])->assertStatus(422);
    expect(DB::table('staff_guardian_challenges')->where('id', $id)->value('attempts'))->toBe(1);
});

it('refuses requests outside an individually configured work window before sending OTP', function () {
    $today = (int) now('Africa/Lome')->dayOfWeekIso;
    DB::table('staff_access_windows')->insert([
        'user_id' => $this->accountant->id, 'enabled' => true,
        'weekdays' => json_encode([($today % 7) + 1]), 'start_time' => '00:00',
        'end_time' => '23:59', 'timezone' => 'Africa/Lome',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->postJson('/api/v1/auth/staff/login/start', ['email' => $this->accountant->email])->assertForbidden();
    expect($this->delivered->messages)->toBeEmpty();
});

it('does not allow the SuperAdmin or an unactivated staff identity through the staff endpoint', function () {
    $superadmin = User::role('superadmin')->firstOrFail();
    $this->postJson('/api/v1/auth/staff/login/start', ['email' => $superadmin->email])->assertForbidden();
    $this->accountant->forceFill(['status' => UserStatus::PendingActivation])->save();
    $this->postJson('/api/v1/auth/staff/login/start', ['email' => $this->accountant->email])->assertForbidden();
    expect($this->delivered->messages)->toBeEmpty();
});

it('does not expose the endpoint while the guardian feature flag is disabled', function () {
    config(['staff_guardian.enabled' => false]);
    $this->postJson('/api/v1/auth/staff/login/start', ['email' => $this->accountant->email])->assertNotFound();
});
