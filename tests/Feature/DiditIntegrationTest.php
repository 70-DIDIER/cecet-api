<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Kyc\Models\MemberKycCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    seedCatalogs();
    if (DB::connection()->getDatabaseName() !== 'cectapi_test') {
        throw new RuntimeException('Didit tests must run only on cectapi_test.');
    }
    config()->set('kyc.provider', 'didit');
    config()->set('kyc.didit.api_key', 'test-no-real-key');
    config()->set('kyc.didit.workflow_id', 'test-workflow');
    config()->set('kyc.didit.webhook_secret', 'test-secret-in-memory');
    config()->set('kyc.didit.environment', 'sandbox');
    config()->set('kyc.didit.auto_approve', false);
    config()->set('staff_guardian.enabled', false);
});

function fakeDiditCreation(): void
{
    Http::fake([
        'verification.didit.me/v3/session/' => Http::response([
            'session_id' => '123e4567-e89b-42d3-a456-426614174000',
            'url' => 'https://verify.didit.me/verification/test-link',
        ], 201),
    ]);
}
function fakeDiditDecision(string $status, bool $active = true): void
{
    Http::fake([
        'verification.didit.me/v3/session/*/decision/' => Http::response([
            'session_id' => '123e4567-e89b-42d3-a456-426614174000',
            'status' => $status,
            'decision' => [
                'id_verifications' => [['status' => 'Approved']],
                'liveness_checks' => [['status' => 'Approved', 'method' => $active ? '3D_ACTION' : 'PASSIVE']],
                'face_matches' => [['status' => 'Approved']],
            ],
        ], 200),
    ]);
}
function startDidit(User $member): \Illuminate\Testing\TestResponse
{
    return test()->withToken(tokenFor($member))->postJson('/api/v1/me/kyc/didit/start', [
        'privacy_notice_accepted' => true,
    ]);
}

it('rejects a missing Didit credential and never grants payment access', function () {
    config()->set('kyc.didit.api_key', null);
    $member = User::factory()->status(UserStatus::PendingKyc)->create();
    startDidit($member)->assertStatus(503);
    expect(MemberKycCase::count())->toBe(0);
    $this->withToken(tokenFor($member))->getJson('/api/v1/me/membership')->assertForbidden();
});

it('creates only one hosted session and prevents manual document bypass', function () {
    fakeDiditCreation();
    $member = User::factory()->status(UserStatus::PendingKyc)->create();

    startDidit($member)->assertOk()
        ->assertJsonPath('data.url', 'https://verify.didit.me/verification/test-link');
    $case = MemberKycCase::where('user_id', $member->id)->sole();
    expect($case->verification_mode)->toBe('didit');
    expect($case->provider_status)->toBe('Not Started');
    expect(DB::table('member_kyc_cases')->where('id', $case->id)->value('provider_session_url'))
        ->not->toContain('verify.didit.me');
    startDidit($member)->assertOk();
    expect(MemberKycCase::count())->toBe(1);
    $this->withToken(tokenFor($member))->postJson('/api/v1/me/kyc', [])->assertForbidden();
    $this->withToken(tokenFor($member))->getJson('/api/v1/me/membership')->assertForbidden();
});

it('rejects a spoofed or passive-only result but allows an active liveness review', function () {
    fakeDiditCreation();
    $member = User::factory()->status(UserStatus::PendingKyc)->create();
    startDidit($member)->assertOk();
    $case = MemberKycCase::where('user_id', $member->id)->sole();

    fakeDiditDecision('Approved', false);
    $this->withToken(tokenFor($member))->postJson('/api/v1/me/kyc/didit/refresh', [])
        ->assertOk()->assertJsonPath('data.status', 'submitted');
    expect($case->refresh()->provider_checks['complete'])->toBeFalse();
    expect($case->provider_verified_at)->toBeNull();
    $validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
    $this->withToken(tokenFor($validator, withStepUp: true))
        ->postJson('/api/v1/kyc/cases/'.$case->id.'/decision', ['decision' => 'approved'])
        ->assertStatus(409);

    fakeDiditDecision('Approved', true);
    $this->withToken(tokenFor($member))->postJson('/api/v1/me/kyc/didit/refresh', [])->assertOk();
    expect($case->refresh()->provider_checks['complete'])->toBeTrue();
    expect($case->provider_verified_at)->not->toBeNull();
    $this->withToken(tokenFor($validator, withStepUp: true))
        ->postJson('/api/v1/kyc/cases/'.$case->id.'/decision', ['decision' => 'approved'])
        ->assertOk();
    expect($member->refresh()->status)->toBe(UserStatus::PendingPayment);
    $this->withToken(tokenFor($member))->getJson('/api/v1/me/membership')->assertOk();
});

it('verifies the webhook signature and fetches the independent session decision', function () {
    fakeDiditCreation();
    $member = User::factory()->status(UserStatus::PendingKyc)->create();
    startDidit($member)->assertOk();
    $case = MemberKycCase::where('user_id', $member->id)->sole();
    fakeDiditDecision('In Review');

    $body = json_encode([
        'event' => 'status.updated',
        'session_id' => $case->provider_reference,
        'status' => 'Approved', // forged verdict must NOT override independent decision
        'timestamp' => time(),
    ], JSON_THROW_ON_ERROR);
    $this->call('POST', '/api/v1/webhooks/kyc/didit', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => 'bad-signature',
    ], $body)->assertUnauthorized();

    $this->call('POST', '/api/v1/webhooks/kyc/didit', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 'test-secret-in-memory'),
    ], $body)->assertOk();

    expect($case->refresh()->provider_status)->toBe('In Review');
    expect($case->status)->toBe('submitted');
    expect($member->refresh()->status)->toBe(UserStatus::PendingKyc);
});
