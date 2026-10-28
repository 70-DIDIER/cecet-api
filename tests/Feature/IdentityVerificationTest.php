<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Identity\Enums\IdentityVerificationStatus;
use App\Domain\Identity\Models\IdentityVerification;
use App\Domain\Notification\Models\MemberNotification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    Storage::fake(IdentityVerification::DISK);
    $this->member = User::factory()->identityUnverified()->create();
    $this->admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function submitIdentity(User $member, array $overrides = []): TestResponse
{
    return test()->withToken(tokenFor($member))->post('/api/v1/me/identity-verification', [
        'document_type' => 'cni',
        'document_number' => 'TG-123456',
        'front' => UploadedFile::fake()->image('front.jpg'),
        'back' => UploadedFile::fake()->image('back.jpg'),
        'selfie' => UploadedFile::fake()->image('selfie.jpg'),
        ...$overrides,
    ], ['Accept' => 'application/json']);
}

function subscribeToPlan(User $member, string $planId): TestResponse
{
    return test()->withToken(tokenFor($member))
        ->postJson('/api/v1/me/packs', ['plan_id' => $planId], ['Idempotency-Key' => (string) Str::ulid()]);
}

it('refuses a pack subscription until the identity is approved', function () {
    $plan = makePlan(PackFamily::Consommateur, 'C1');

    subscribeToPlan($this->member, $plan->id)
        ->assertForbidden()
        ->assertJsonPath('code', 'IDENTITY_VERIFICATION_REQUIRED');

    $verificationId = submitIdentity($this->member)->assertCreated()->json('data.id');

    subscribeToPlan($this->member, $plan->id)->assertForbidden();

    $this->withToken(tokenFor($this->admin, withStepUp: true))
        ->postJson("/api/v1/admin/identity-verifications/{$verificationId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    expect($this->member->fresh()->identity_verified_at)->not->toBeNull();
    subscribeToPlan($this->member, $plan->id)->assertSuccessful();
});

it('stores the document and selfie privately and reports the pending status to the member', function () {
    submitIdentity($this->member)
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonMissingPath('data.files');

    $verification = IdentityVerification::query()->sole();
    Storage::disk(IdentityVerification::DISK)->assertExists([$verification->front_path, $verification->back_path, $verification->selfie_path]);

    $this->withToken(tokenFor($this->member))->getJson('/api/v1/me/identity-verification')
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.latest.document_type', 'cni')
        ->assertJsonCount(4, 'data.document_types');

    expect(AuditEvent::query()->where('action', 'identity_verifications.submitted')->exists())->toBeTrue();
});

it('requires the back side of cards but not of a passport', function () {
    submitIdentity($this->member, ['back' => null])
        ->assertUnprocessable()
        ->assertJsonStructure(['details' => ['fields' => ['back']]]);

    submitIdentity($this->member, ['document_type' => 'passport', 'back' => null])->assertCreated();

    expect(IdentityVerification::query()->sole()->back_path)->toBeNull();
});

it('rejects missing selfies, unknown document types and non-image selfies', function () {
    submitIdentity($this->member, ['selfie' => null])->assertUnprocessable()->assertJsonStructure(['details' => ['fields' => ['selfie']]]);
    submitIdentity($this->member, ['document_type' => 'driving_licence'])->assertUnprocessable()->assertJsonStructure(['details' => ['fields' => ['document_type']]]);
    submitIdentity($this->member, ['selfie' => UploadedFile::fake()->create('selfie.pdf', 10, 'application/pdf')])
        ->assertUnprocessable()
        ->assertJsonStructure(['details' => ['fields' => ['selfie']]]);

    expect(IdentityVerification::query()->count())->toBe(0);
});

it('refuses a second submission while one is pending or once verified', function () {
    submitIdentity($this->member)->assertCreated();
    submitIdentity($this->member)->assertConflict()->assertJsonPath('code', 'IDENTITY_VERIFICATION_PENDING');

    $verified = User::factory()->create();
    submitIdentity($verified)->assertConflict()->assertJsonPath('code', 'IDENTITY_ALREADY_VERIFIED');

    expect(IdentityVerification::query()->count())->toBe(1);
});

it('lets a rejected member see the reason and submit new documents', function () {
    $verificationId = submitIdentity($this->member)->json('data.id');

    $this->withToken(tokenFor($this->admin, withStepUp: true))
        ->postJson("/api/v1/admin/identity-verifications/{$verificationId}/reject", ['reason' => 'Photo de la pièce illisible'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    $this->withToken(tokenFor($this->member))->getJson('/api/v1/me/identity-verification')
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.latest.rejection_reason', 'Photo de la pièce illisible');

    expect(MemberNotification::query()->where('user_id', $this->member->id)->where('event', 'identity.rejected')->exists())->toBeTrue()
        ->and($this->member->fresh()->identity_verified_at)->toBeNull();

    submitIdentity($this->member)->assertCreated();
});

it('lists pending verifications for staff and serves their files with an audit trail', function () {
    $verificationId = submitIdentity($this->member)->json('data.id');
    $token = tokenFor($this->admin);

    $this->withToken($token)->getJson('/api/v1/admin/identity-verifications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.member.id', $this->member->id)
        ->assertJsonPath('data.0.files', ['front', 'back', 'selfie']);

    $this->withToken($token)->get("/api/v1/admin/identity-verifications/{$verificationId}/files/selfie")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(AuditEvent::query()->where('action', 'identity_verifications.file_viewed')->exists())->toBeTrue();
});

it('keeps documents away from members and staff without the permission', function () {
    $verificationId = submitIdentity($this->member)->json('data.id');
    $accounting = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();

    $this->withToken(tokenFor($this->member))->getJson("/api/v1/admin/identity-verifications/{$verificationId}")->assertForbidden();
    $this->withToken(tokenFor($accounting))->get("/api/v1/admin/identity-verifications/{$verificationId}/files/front")->assertForbidden();
    $this->withToken(tokenFor($accounting, withStepUp: true))->postJson("/api/v1/admin/identity-verifications/{$verificationId}/approve")->assertForbidden();

    expect(IdentityVerification::query()->sole()->status)->toBe(IdentityVerificationStatus::Pending);
});

it('requires a step-up and refuses to decide twice', function () {
    $verificationId = submitIdentity($this->member)->json('data.id');

    $this->withToken(tokenFor($this->admin))->postJson("/api/v1/admin/identity-verifications/{$verificationId}/approve")->assertForbidden();

    $token = tokenFor($this->admin, withStepUp: true);
    $this->withToken($token)->postJson("/api/v1/admin/identity-verifications/{$verificationId}/approve")->assertOk();
    $this->withToken($token)->postJson("/api/v1/admin/identity-verifications/{$verificationId}/reject", ['reason' => 'Changement d\'avis'])
        ->assertConflict()
        ->assertJsonPath('code', 'IDENTITY_VERIFICATION_NOT_PENDING');

    expect(MemberNotification::query()->where('user_id', $this->member->id)->where('event', 'identity.approved')->exists())->toBeTrue();
});
