<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Kyc\Models\MemberKycCase;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Pest/Laravel initializes the application as part of the first catalog seed.
    // phpunit.xml forces the isolated test DB regardless of the staging .env.
    seedCatalogs();
    if (\Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'cectapi_test') {
        throw new RuntimeException('KYC tests may only run on cectapi_test.');
    }
    config()->set('staff_guardian.enabled', false);
    config()->set('kyc.provider', 'manual');
    Storage::fake('local');
});

function submitKyc(User $member): \Illuminate\Testing\TestResponse
{
    return test()->withToken(tokenFor($member))->post('/api/v1/me/kyc', [
        'document_type' => 'national_id',
        'document_number' => 'TG-1234567890',
        'document_country' => 'TG',
        'date_of_birth' => '1994-06-15',
        'address' => 'Lomé, quartier administratif, rue 12',
        'document_front' => UploadedFile::fake()->create('recto.pdf', 100, 'application/pdf'),
        'document_back' => UploadedFile::fake()->create('verso.pdf', 100, 'application/pdf'),
        'privacy_notice_accepted' => true,
    ], ['Accept' => 'application/json']);
}

it('enforces email then KYC before accessing any payment or commercial endpoint', function () {
    $member = User::factory()->status(UserStatus::PendingKyc)->create();

    $this->withToken(tokenFor($member))->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.status', 'pending_kyc');
    $this->withToken(tokenFor($member))->getJson('/api/v1/me/kyc')->assertOk()
        ->assertJsonPath('data.kyc', null);
    $this->withToken(tokenFor($member))->getJson('/api/v1/me/membership')->assertForbidden();
    $this->withToken(tokenFor($member))->postJson('/api/v1/me/membership/payments', ['method' => 'TMONEY'], ['Idempotency-Key' => (string) \Illuminate\Support\Str::ulid()])->assertForbidden();
    $this->withToken(tokenFor($member))->getJson('/api/v1/me/dashboard')->assertForbidden();
    $this->withToken(tokenFor($member))->getJson('/api/v1/me/packs')->assertForbidden();
    $this->withToken(tokenFor($member))->getJson('/api/v1/me/merchant-payments')->assertForbidden();
});

it('stores encrypted identity fields, private documents, and approves only through KYC validator', function () {
    $member = User::factory()->status(UserStatus::PendingKyc)->create();
    $validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();

    submitKyc($member)->assertCreated()->assertJsonPath('data.status', 'submitted');

    $case = MemberKycCase::query()->where('user_id', $member->id)->sole();
    expect($case->identity_payload['document_number'])->toBe('TG-1234567890');
    expect(\Illuminate\Support\Facades\DB::table('member_kyc_cases')->where('id', $case->id)->value('identity_payload'))
        ->not->toContain('TG-1234567890');
    expect($case->document_front_path)->toStartWith('kyc/'.$member->id.'/');
    Storage::disk('local')->assertExists($case->document_front_path);
    expect($case->document_front_path)->toEndWith('.enc');
    expect(Storage::disk('local')->get($case->document_front_path))->toStartWith('eyJ');

    // The member can neither review themselves nor resubmit an undecided dossier.
    $this->withToken(tokenFor($member))->getJson('/api/v1/kyc/cases')->assertForbidden();
    submitKyc($member)->assertStatus(409);

    $this->withToken(tokenFor($validator))->getJson('/api/v1/kyc/cases')->assertOk()
        ->assertJsonPath('meta.total', 1);
    $this->withToken(tokenFor($validator))->getJson('/api/v1/kyc/cases/'.$case->id)->assertOk()
        ->assertJsonPath('data.identity.document_number', 'TG-1234567890');
    $this->withToken(tokenFor($validator))->get('/api/v1/kyc/cases/'.$case->id.'/documents/front')->assertOk();
    expect(\Illuminate\Support\Facades\DB::table('audit_events')->where('action', 'kyc.document_accessed')->count())->toBe(1);

    $this->withToken(tokenFor($validator, withStepUp: true))
        ->postJson('/api/v1/kyc/cases/'.$case->id.'/decision', ['decision' => 'approved'])
        ->assertOk()->assertJsonPath('data.status', 'approved');

    $member->refresh();
    expect($member->status)->toBe(UserStatus::PendingPayment);
    $this->withToken(tokenFor($member))->getJson('/api/v1/me/membership')->assertOk();
    $this->withToken(tokenFor($member))->getJson('/api/v1/me/packs')->assertForbidden();
});

it('requires a written refusal reason and allows a corrected submission', function () {
    $member = User::factory()->status(UserStatus::PendingKyc)->create();
    $validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();

    submitKyc($member)->assertCreated();
    $case = MemberKycCase::query()->where('user_id', $member->id)->sole();

    $this->withToken(tokenFor($validator, withStepUp: true))
        ->postJson('/api/v1/kyc/cases/'.$case->id.'/decision', ['decision' => 'rejected', 'reason' => 'x'])
        ->assertUnprocessable();

    $this->withToken(tokenFor($validator, withStepUp: true))
        ->postJson('/api/v1/kyc/cases/'.$case->id.'/decision', ['decision' => 'needs_information', 'reason' => 'Le document fourni est illisible.'])
        ->assertOk();

    submitKyc($member)->assertCreated()->assertJsonPath('data.status', 'submitted');
    expect($member->refresh()->status)->toBe(UserStatus::PendingKyc);
});


it('rejects a forged payment-ready status without an approved KYC case', function () {
    $member = User::factory()->status(UserStatus::PendingPayment)->create();
    $this->withToken(tokenFor($member))->getJson('/api/v1/me/membership')->assertForbidden();
    $this->withToken(tokenFor($member))->postJson('/api/v1/me/membership/payments',
        ['method' => 'TMONEY'], ['Idempotency-Key' => (string) \Illuminate\Support\Str::ulid()])
        ->assertForbidden();

    $accountant = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();
    $this->withToken(tokenFor($accountant))->getJson('/api/v1/kyc/cases')->assertForbidden();
});
