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
    config()->set('kyc.didit.application_id', '3d71e41c-317b-49d8-ba1c-c220866328f5f');
    config()->set('kyc.didit.webhook_secret', 'test-secret-in-memory');
    config()->set('kyc.didit.environment', 'sandbox');
    config()->set('kyc.didit.auto_approve', false);
    config()->set('staff_guardian.enabled', false);
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
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
    // Replace the previous fixture rather than appending a competing URL stub.
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'verification.didit.me/v3/session/*/decision/' => Http::response([
            'session_id' => '123e4567-e89b-42d3-a456-426614174000',
            'status' => $status,
            'decision' => [
                'id_verifications' => [['status' => 'Approved']],
                'liveness_checks' => [['status' => 'Approved', 'method' => $active ? 'ACTIVE_3D' : 'PASSIVE']],
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

    config()->set('kyc.didit.document_only_test', true);
    $this->withToken(tokenFor($member))->postJson('/api/v1/me/kyc/didit/start', [
        'privacy_notice_accepted' => true,
        'restart_sandbox' => true,
    ])->assertOk();
    Http::assertSentCount(2);
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
    \Illuminate\Support\Facades\Queue::fake();

    $body = json_encode([
        'event' => 'status.updated',
        'environment' => 'sandbox',
        'application_id' => '3d71e41c-317b-49d8-ba1c-c220866328f5f',
        'session_id' => $case->provider_reference,
        'status' => 'Approved', // forged verdict must NOT override independent decision
        'timestamp' => time(),
    ], JSON_THROW_ON_ERROR);
    $this->call('POST', '/api/v1/webhooks/kyc/didit', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => 'bad-signature',
        'HTTP_X_TIMESTAMP' => (string) time(),
    ], $body)->assertUnauthorized();

    $this->call('POST', '/api/v1/webhooks/kyc/didit', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 'test-secret-in-memory'),
        'HTTP_X_TIMESTAMP' => (string) time(),
    ], $body)->assertOk();

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Domain\Kyc\Jobs\ProcessDiditDecision::class, 1);
    app(\App\Domain\Kyc\Jobs\ProcessDiditDecision::class, ['caseId' => $case->id])
        ->handle(app(\App\Domain\Kyc\Didit\DiditDecisionProcessor::class));
    expect($case->refresh()->provider_status)->toBe('In Review');
    expect($case->status)->toBe('submitted');
    expect($member->refresh()->status)->toBe(UserStatus::PendingKyc);
});


it('rejects wrong-environment signed callbacks before queuing and supports full V2 signatures', function () {
    fakeDiditCreation();
    $member = User::factory()->status(UserStatus::PendingKyc)->create();
    startDidit($member)->assertOk();
    $case = MemberKycCase::where('user_id', $member->id)->sole();
    \Illuminate\Support\Facades\Queue::fake();

    $wrong = ['timestamp' => time(), 'session_id' => $case->provider_reference,
        'environment' => 'live', 'application_id' => '3d71e41c-317b-49d8-ba1c-c220866328f5f', 'event' => 'status.updated'];
    ksort($wrong, SORT_STRING);
    $rawWrong = json_encode($wrong, JSON_THROW_ON_ERROR);
    $this->call('POST', '/api/v1/webhooks/kyc/didit', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE_V2' => hash_hmac('sha256', $rawWrong, 'test-secret-in-memory'),
        'HTTP_X_TIMESTAMP' => (string) $wrong['timestamp'],
    ], $rawWrong)->assertStatus(409);
    \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Domain\Kyc\Jobs\ProcessDiditDecision::class);

    $correct = ['timestamp' => time(), 'session_id' => $case->provider_reference,
        'environment' => 'sandbox', 'application_id' => '3d71e41c-317b-49d8-ba1c-c220866328f5f', 'event' => 'status.updated'];
    ksort($correct, SORT_STRING);
    $rawGood = json_encode($correct, JSON_THROW_ON_ERROR);
    $this->call('POST', '/api/v1/webhooks/kyc/didit', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE_V2' => hash_hmac('sha256', $rawGood, 'test-secret-in-memory'),
        'HTTP_X_TIMESTAMP' => (string) $correct['timestamp'],
    ], $rawGood)->assertOk();
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Domain\Kyc\Jobs\ProcessDiditDecision::class, 1);
});


it('recognizes Didit documented ACTIVE_3D result and canonical V2 with empty JSON objects', function () {
    $gateway = app(\App\Domain\Kyc\Didit\DiditGateway::class);
    $decision = [
        'decision' => [
            'id_verifications' => [['status' => 'Approved', 'verification_method' => 'document', 'warnings' => []]],
            'liveness_checks' => [['status' => 'Approved', 'method' => 'ACTIVE_3D', 'warnings' => []]],
            'face_matches' => [['status' => 'Approved', 'warnings' => []]],
            'risk_view' => ['countries' => ['risk_scores' => new \stdClass()]],
        ],
    ];
    expect($gateway->checks($decision)['complete'])->toBeTrue();
    $obj = json_decode('{"timestamp":1774950000,"session_id":"test-session","environment":"sandbox","metadata":{},"risk_view":{"countries":{"risk_scores":{}}},"display_name":"José"}');
    $json = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $canonical = '{"display_name":"José","environment":"sandbox","metadata":{},"risk_view":{"countries":{"risk_scores":{}}},"session_id":"test-session","timestamp":1774950000}';
    $sig = hash_hmac('sha256', $canonical, 'test-secret-in-memory');
    expect($gateway->signatureValid($json, $sig, null))->toBeTrue();
    expect($gateway->signatureValid($json, str_repeat('0', 64), null))->toBeFalse();
});

it('does not allow a console Try Webhook to enqueue a session and rejects a mismatched timestamp header', function () {
    fakeDiditCreation();
    $member = User::factory()->status(UserStatus::PendingKyc)->create();
    startDidit($member)->assertOk();
    $case = MemberKycCase::where('user_id', $member->id)->sole();
    \Illuminate\Support\Facades\Queue::fake();
    $timestamp = time();
    $payload = [
        'webhook_type' => 'status.updated',
        'timestamp' => $timestamp,
        'session_id' => $case->provider_reference,
        'environment' => 'sandbox',
        'application_id' => '3d71e41c-317b-49d8-ba1c-c220866328f5f',
    ];
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $sig = hash_hmac('sha256', $raw, 'test-secret-in-memory');

    $this->call('POST', '/api/v1/webhooks/kyc/didit', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => $sig,
        'HTTP_X_TIMESTAMP' => (string) ($timestamp - 120),
    ], $raw)->assertUnauthorized();
    $this->call('POST', '/api/v1/webhooks/kyc/didit', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => $sig,
        'HTTP_X_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_DIDIT_TEST_WEBHOOK' => 'true',
    ], $raw)->assertOk()->assertJsonPath('test', true);
    \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Domain\Kyc\Jobs\ProcessDiditDecision::class);
});


it('migrates legacy pending-payment members through Didit before allowing membership', function () {
    fakeDiditCreation();
    $legacy = User::factory()->status(UserStatus::PendingPayment)->create();
    $this->withToken(tokenFor($legacy))->getJson('/api/v1/me/membership')->assertForbidden();

    startDidit($legacy)->assertOk();
    $case = MemberKycCase::query()->where('user_id', $legacy->id)->sole();
    expect($case->status)->toBe('provider_pending');

    fakeDiditDecision('Approved', true);
    $this->withToken(tokenFor($legacy))->postJson('/api/v1/me/kyc/didit/refresh', [])->assertOk();
    expect($case->refresh()->provider_checks['complete'])->toBeTrue();
    $reviewer = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
    $this->withToken(tokenFor($reviewer, withStepUp: true))
        ->postJson('/api/v1/kyc/cases/'.$case->id.'/decision', ['decision' => 'approved'])
        ->assertOk();
    expect($legacy->refresh()->status)->toBe(UserStatus::PendingPayment);
    $this->withToken(tokenFor($legacy))->getJson('/api/v1/me/membership')->assertOk();
});


it('uses the separate KYB workflow for a company and never silently falls back to person KYC', function () {
    fakeDiditCreation();
    $case = new MemberKycCase(['user_id' => (string) \Illuminate\Support\Str::ulid()]);
    $case->id = (string) \Illuminate\Support\Str::ulid();
    config()->set('kyc.didit.kyb_workflow_id', null);
    try {
        app(\App\Domain\Kyc\Didit\DiditGateway::class)->create($case, true);
        throw new RuntimeException('KYB should fail closed without a company workflow');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(503);
    }

    config()->set('kyc.didit.kyb_workflow_id', 'company-kyb-workflow');
    $result = app(\App\Domain\Kyc\Didit\DiditGateway::class)->create($case, true);
    expect($result['workflow_id'])->toBe('company-kyb-workflow');
    Http::assertSent(fn ($request) => $request['workflow_id'] === 'company-kyb-workflow');
});


it('limits both PF and legal-person test applications to a document-only session, but preserves company KYB for real mode', function () {
    config()->set('kyc.didit.kyb_workflow_id', 'company-kyb-workflow');
    config()->set('kyc.didit.document_only_test', true);
    fakeDiditCreation();

    $legal = User::factory()->legalPerson()->status(UserStatus::PendingKyc)->create();
    startDidit($legal)->assertOk();
    $case = MemberKycCase::where('user_id', $legal->id)->sole();
    expect($case->provider_workflow_id)->toBe('test-workflow');
    Http::assertSent(fn ($request) => $request['workflow_id'] === 'test-workflow');
    $this->withToken(tokenFor($legal))->getJson('/api/v1/me/membership')->assertForbidden();

    // Both fake sessions use the same test-only reference; free it before the second assertion.
    $case->delete();
    config()->set('kyc.didit.document_only_test', false);
    fakeDiditCreation();
    $secondLegal = User::factory()->legalPerson()->status(UserStatus::PendingKyc)->create();
    startDidit($secondLegal)->assertOk();
    expect(MemberKycCase::where('user_id', $secondLegal->id)->sole()->provider_workflow_id)
        ->toBe('company-kyb-workflow');
});
