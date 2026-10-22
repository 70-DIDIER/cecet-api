<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Identity\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('preserves paid membership, KYC approval and pack access', function () {
    seedCatalogs();
    Mail::fake();
    Storage::fake(IdentityVerification::DISK);

    $email = 'e2e-kyc-member@example.test';
    $registration = [
        'person_type' => 'natural', 'first_name' => 'Test', 'last_name' => 'Member',
        'email' => $email, 'phone' => '+22890112233', 'pernum' => 'KYC-E2E-1',
        'password' => 'ExamplePass2026!', 'password_confirmation' => 'ExamplePass2026!',
        'commitment_accepted' => true, 'signed_place' => 'Lome',
        'signed_on' => now()->toDateString(),
    ];
    $challenge = $this->postJson('/api/v1/auth/register', $registration,
        ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()->json('data.verification.challenge_id');

    $this->postJson('/api/v1/auth/email/verify', [
        'challenge_id' => $challenge, 'code' => lastOtpCode($email),
        'device_name' => 'regression',
    ])->assertOk()->assertJsonPath('data.user.status', 'pending_payment');

    $member = User::query()->where('email', $email)->sole();
    $attemptId = $this->withToken(tokenFor($member))
        ->postJson('/api/v1/me/membership/payments', ['method' => 'TMONEY'],
            ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()->json('data.id');
    $this->withToken(tokenFor($member))
        ->postJson("/api/v1/me/payments/{$attemptId}/simulate")
        ->assertOk();
    expect($member->fresh()->status->value)->toBe('active');

    $plan = makePlan(PackFamily::Consommateur, 'KYC-1');
    $this->withToken(tokenFor($member))
        ->postJson('/api/v1/me/packs', ['plan_id' => $plan->id],
            ['Idempotency-Key' => (string) Str::ulid()])
        ->assertForbidden()->assertJsonPath('code', 'IDENTITY_VERIFICATION_REQUIRED');

    $verification = $this->withToken(tokenFor($member))
        ->post('/api/v1/me/identity-verification', [
            'document_type' => 'passport',
            'front' => UploadedFile::fake()->image('document.jpg'),
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');

    $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    $this->withToken(tokenFor($admin, withStepUp: true))
        ->postJson("/api/v1/admin/identity-verifications/{$verification}/approve")
        ->assertOk()->assertJsonPath('data.status', 'approved');

    $pack = subscribeAndPay($member, $plan);
    expect($pack->fresh()->status->value)->toBe('active');
    $this->artisan('ledger:verify')->assertSuccessful();
    $this->artisan('audit:verify')->assertSuccessful();
});
