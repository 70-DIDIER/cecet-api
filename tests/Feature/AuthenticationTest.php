<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
});

function login(string $email, string $password = 'Password123'): TestResponse
{
    return test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password, 'device_name' => 'tests']);
}

it('issues an expiring token to a member', function () {
    $this->freezeTime();
    $member = User::factory()->create();

    $response = login(mb_strtoupper($member->email))->assertOk()
        ->assertJsonPath('data.status', 'authenticated')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.id', $member->id)
        ->assertJsonPath('data.expires_at', now()->addMinutes(10080)->toIso8601ZuluString());

    $this->withToken($response->json('data.access_token'))->getJson('/api/v1/me')->assertOk();
    expect($member->refresh()->last_login_at)->not->toBeNull();
});

it('answers identically for a wrong password and an unknown account', function () {
    $member = User::factory()->create();

    $wrongPassword = login($member->email, 'WrongPass123')->assertUnauthorized()->json();
    $unknownAccount = login('nobody@example.com', 'WrongPass123')->assertUnauthorized()->json();

    expect([$wrongPassword['code'], $wrongPassword['message']])->toBe([$unknownAccount['code'], $unknownAccount['message']])
        ->and($wrongPassword['code'])->toBe('INVALID_CREDENTIALS')
        ->and(SecurityEvent::query()->where('event_type', 'auth.login_failed')->count())->toBe(2);
});

it('locks the login temporarily after the configured number of failures', function () {
    app()->detectEnvironment(fn () => 'production');
    $member = User::factory()->create();
    app(SettingsService::class)->set('auth.login_max_attempts', 3, 'Durcissement');

    foreach (range(1, 3) as $attempt) {
        login($member->email, 'WrongPass123')->assertUnauthorized();
    }

    login($member->email)->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_LOGIN_ATTEMPTS');

    $this->travel(901)->seconds();
    login($member->email)->assertOk();
});

it('refuses disabled or not yet activated accounts', function (UserStatus $status, string $code) {
    $user = User::factory()->status($status)->create();

    login($user->email)->assertForbidden()->assertJsonPath('code', $code);
})->with([
    'suspended' => [UserStatus::Suspended, 'ACCOUNT_DISABLED'],
    'closed' => [UserStatus::Closed, 'ACCOUNT_DISABLED'],
    'staff pending activation' => [UserStatus::PendingActivation, 'ACCOUNT_NOT_ACTIVATED'],
]);

it('sends a new verification code to a member whose email is not verified', function () {
    $member = User::factory()->unverified()->create();

    $response = login($member->email)->assertForbidden()->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

    $this->postJson('/api/v1/auth/email/verify', [
        'challenge_id' => $response->json('details.challenge_id'),
        'code' => lastOtpCode($member->email),
        'device_name' => 'tests',
    ])->assertOk()->assertJsonPath('data.user.status', 'pending_payment');
});

it('always requires an email code for internal accounts', function () {
    $staff = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();

    $response = login($staff->email)->assertStatus(202)
        ->assertJsonPath('data.status', 'otp_required')
        ->assertJsonPath('data.purpose', 'login')
        ->assertJsonMissingPath('data.access_token');

    $this->postJson('/api/v1/auth/login/verify', [
        'challenge_id' => $response->json('data.challenge_id'),
        'code' => lastOtpCode($staff->email),
        'device_name' => 'tests',
    ])->assertOk()->assertJsonPath('data.user.roles', [PermissionCatalog::ACCOUNTING]);
});

it('requires an email code for members when configured', function () {
    app(SettingsService::class)->set('otp.required_on_member_login', true, 'OTP pour tous');

    login(User::factory()->create()->email)->assertStatus(202)->assertJsonPath('data.status', 'otp_required');
});

it('gives internal accounts shorter sessions', function () {
    $this->freezeTime();
    $staff = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    $challengeId = login($staff->email)->json('data.challenge_id');

    $this->postJson('/api/v1/auth/login/verify', ['challenge_id' => $challengeId, 'code' => lastOtpCode(), 'device_name' => 'tests'])
        ->assertJsonPath('data.expires_at', now()->addMinutes(480)->toIso8601ZuluString());
});

it('rejects an expired token', function () {
    $member = User::factory()->create();
    $token = login($member->email)->json('data.access_token');

    $this->travel(10081)->minutes();

    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('logs out by revoking the current token', function () {
    $member = User::factory()->create();
    $token = tokenFor($member);

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($member->tokens()->count())->toBe(0);
});

it('lists sessions and revokes the other devices', function () {
    $member = User::factory()->create();
    $current = tokenFor($member);
    tokenFor($member);
    tokenFor($member);

    $this->withToken($current)->getJson('/api/v1/me/sessions')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonFragment(['current' => true]);

    $this->withToken($current)->postJson('/api/v1/me/sessions/revoke-others')->assertJsonPath('data.revoked', 2);

    expect($member->tokens()->count())->toBe(1);
});

it('cannot revoke the session of another account', function () {
    $owner = User::factory()->create();
    $otherToken = User::factory()->create()->createToken('other')->accessToken;

    $this->withToken(tokenFor($owner))->deleteJson("/api/v1/me/sessions/{$otherToken->id}")->assertNotFound();

    expect($otherToken->fresh())->not->toBeNull();
});

it('resets a password by email code and revokes every session', function () {
    $member = User::factory()->create();
    tokenFor($member);

    $this->postJson('/api/v1/auth/password/forgot', ['email' => $member->email])->assertStatus(202);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => $member->email,
        'code' => lastOtpCode($member->email),
        'password' => 'Nouveau2026Pass',
        'password_confirmation' => 'Nouveau2026Pass',
    ])->assertOk();

    expect($member->tokens()->count())->toBe(0);
    login($member->email, 'Nouveau2026Pass')->assertOk();
    login($member->email)->assertUnauthorized();
});

it('does not reveal whether an account exists when a reset is requested', function () {
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com'])
        ->assertStatus(202)
        ->assertExactJson(['data' => ['status' => 'requested']]);

    Mail::assertNothingQueued();
});

it('returns a normalized 401 for unauthenticated API requests without an Accept header', function () {
    $this->get('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    $this->get('/api/v1/admin/dashboard')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});
