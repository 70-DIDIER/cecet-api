<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Identity\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('keeps person-morale registration and failed KYC resubmission operational', function () {
    seedCatalogs();
    Mail::fake();
    Storage::fake(IdentityVerification::DISK);

    $email = 'e2e-kyc-company@example.test';
    $challenge = $this->postJson('/api/v1/auth/register', [
        'person_type' => 'legal', 'first_name' => 'Test', 'last_name' => 'Director',
        'legal_name' => 'Entreprise QA SARL', 'legal_form' => 'SARL',
        'email' => $email, 'phone' => '+22890112235', 'pernum' => 'KYC-E2E-PM',
        'password' => 'ExamplePass2026!', 'password_confirmation' => 'ExamplePass2026!',
        'commitment_accepted' => true, 'signed_place' => 'Lome',
        'signed_on' => now()->toDateString(),
    ], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()->assertJsonPath('data.user.person_type', 'legal')
        ->json('data.verification.challenge_id');

    $this->postJson('/api/v1/auth/email/verify', [
        'challenge_id' => $challenge, 'code' => lastOtpCode($email),
        'device_name' => 'regression',
    ])->assertOk()->assertJsonPath('data.user.status', 'pending_payment');

    $company = User::query()->where('email', $email)->sole();
    $attempt = $this->withToken(tokenFor($company))
        ->postJson('/api/v1/me/membership/payments', ['method' => 'TMONEY'],
            ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()->json('data.id');

    $this->withToken(tokenFor($company))
        ->postJson("/api/v1/me/payments/{$attempt}/simulate")
        ->assertOk();

    $plan = makePlan(PackFamily::Marchand, 'KYC-PM', periodicTerms([
        'eligible_person_types' => ['legal'],
    ]));
    $this->withToken(tokenFor($company))->postJson('/api/v1/me/packs', [
        'plan_id' => $plan->id,
    ], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertForbidden()->assertJsonPath('code', 'IDENTITY_VERIFICATION_REQUIRED');

    $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();

    foreach (['reject', 'approve'] as $decision) {
        $verification = $this->withToken(tokenFor($company))
            ->post('/api/v1/me/identity-verification', [
                'document_type' => 'cni',
                'document_number' => 'TG-LEGAL-0001',
                'front' => UploadedFile::fake()->image('recto.jpg'),
                'back' => UploadedFile::fake()->image('verso.jpg'),
                'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        $body = $decision === 'reject' ? ['reason' => 'Document illisible'] : [];
        $this->withToken(tokenFor($admin, withStepUp: true))
            ->postJson("/api/v1/admin/identity-verifications/{$verification}/{$decision}", $body)
            ->assertOk()->assertJsonPath('data.status', $decision === 'reject' ? 'rejected' : 'approved');

        $this->withToken(tokenFor($company))->getJson('/api/v1/me/identity-verification')
            ->assertOk()->assertJsonPath('data.status', $decision === 'reject' ? 'rejected' : 'approved');
    }

    $pack = subscribeAndPay($company, $plan);
    expect($pack->fresh()->status->value)->toBe('active');
    $this->artisan('ledger:verify')->assertSuccessful();
    $this->artisan('audit:verify')->assertSuccessful();
});
