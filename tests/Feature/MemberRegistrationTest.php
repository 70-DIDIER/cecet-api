<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Mail\OtpCodeMail;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Http\Middleware\EnsureIdempotency;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function naturalPersonPayload(array $overrides = []): array
{
    return [
        'person_type' => 'natural',
        'first_name' => 'Ama',
        'last_name' => 'Mensah',
        'email' => 'Ama.Mensah@Example.com',
        'phone' => '+22890000001',
        'pernum' => 'PN-0001',
        'password' => 'Solide2026Pass',
        'password_confirmation' => 'Solide2026Pass',
        'commitment_accepted' => true,
        'signed_place' => 'Lomé',
        'signed_on' => now()->toDateString(),
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function legalPersonPayload(array $overrides = []): array
{
    return naturalPersonPayload([
        'person_type' => 'legal',
        'email' => 'contact@boutique.tg',
        'pernum' => 'PNB-0001',
        'legal_name' => 'Boutique Kossi SARL',
        'legal_form' => 'SARL',
        ...$overrides,
    ]);
}

function register(array $payload): TestResponse
{
    return test()->postJson('/api/v1/auth/register', $payload, [EnsureIdempotency::HEADER => (string) Str::ulid()]);
}

it('registers a natural person waiting for email verification', function () {
    $response = register(naturalPersonPayload());

    $response->assertCreated()
        ->assertJsonPath('data.user.status', 'pending_email_verification')
        ->assertJsonPath('data.user.email', 'ama.mensah@example.com')
        ->assertJsonPath('data.user.person_type', 'natural')
        ->assertJsonPath('data.user.pernums.0.kind', 'personal')
        ->assertJsonPath('data.verification.purpose', 'email_verification')
        ->assertJsonPath('data.verification.sent_to', 'a*********@example.com');

    expect($response->json('data.user.member_number'))->toMatch('/^CECT-\d{6}$/');

    Mail::assertQueued(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->hasTo('ama.mensah@example.com'));
    expect(AuditEvent::query()->where('action', 'members.registered')->exists())->toBeTrue();
});

it('registers a legal person with its organization and a business pernum', function () {
    register(legalPersonPayload())
        ->assertCreated()
        ->assertJsonPath('data.user.person_type', 'legal')
        ->assertJsonPath('data.user.organization.legal_name', 'Boutique Kossi SARL')
        ->assertJsonPath('data.user.pernums.0.kind', 'business');
});

it('never exposes the password or the code in the response', function () {
    $response = register(naturalPersonPayload());

    expect($response->getContent())
        ->not->toContain('Solide2026Pass')
        ->not->toContain(lastOtpCode());
});

it('rejects invalid registrations', function (array $payload, string $field) {
    register($payload)->assertStatus(422)->assertJsonStructure(['details' => ['fields' => [$field]]]);
})->with([
    'weak password' => [naturalPersonPayload(['password' => 'faible', 'password_confirmation' => 'faible']), 'password'],
    'phone not E.164' => [naturalPersonPayload(['phone' => '90000001']), 'phone'],
    'legal data on a natural person' => [naturalPersonPayload(['legal_name' => 'X SARL']), 'legal_name'],
    'missing legal name' => [legalPersonPayload(['legal_name' => null]), 'legal_name'],
    'unknown legal form' => [legalPersonPayload(['legal_form' => 'INCONNU']), 'legal_form'],
]);

it('refuses a second account with the same email or pernum', function (array $duplicate, string $field) {
    register(naturalPersonPayload())->assertCreated();

    register(naturalPersonPayload($duplicate))->assertStatus(422)->assertJsonStructure(['details' => ['fields' => [$field]]]);
})->with([
    'same email, other case' => [['email' => 'AMA.MENSAH@example.com', 'pernum' => 'PN-0002'], 'email'],
    'same pernum' => [['email' => 'autre@example.com'], 'pernum'],
]);

it('applies the configured legal form list and RCCM requirement', function () {
    $settings = app(SettingsService::class);
    $settings->set('organization.legal_forms', ['SARL', 'ONG'], 'Ajout des ONG');
    $settings->set('organization.rccm_required', true, 'RCCM exigé au lancement');

    register(legalPersonPayload(['legal_form' => 'ONG']))
        ->assertStatus(422)
        ->assertJsonStructure(['details' => ['fields' => ['rccm']]]);

    register(legalPersonPayload(['legal_form' => 'ONG', 'rccm' => 'TG-LOM-2026-B-001']))->assertCreated();
});

it('requires an idempotency key', function () {
    $this->postJson('/api/v1/auth/register', naturalPersonPayload())
        ->assertStatus(400)
        ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
});

it('moves the account to pending KYC once the email is verified', function () {
    $challengeId = register(naturalPersonPayload())->json('data.verification.challenge_id');

    $response = $this->postJson('/api/v1/auth/email/verify', [
        'challenge_id' => $challengeId,
        'code' => lastOtpCode(),
        'device_name' => 'mobile',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'authenticated')
        ->assertJsonPath('data.user.status', 'pending_kyc');

    $this->withToken($response->json('data.access_token'))->getJson('/api/v1/me')->assertOk();
});

it('rejects a wrong code, then locks the challenge after the configured attempts', function () {
    $challengeId = register(naturalPersonPayload())->json('data.verification.challenge_id');
    $code = lastOtpCode();
    $wrongCode = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 4) as $attempt) {
        $this->postJson('/api/v1/auth/email/verify', ['challenge_id' => $challengeId, 'code' => $wrongCode, 'device_name' => 'mobile'])
            ->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
    }

    $this->postJson('/api/v1/auth/email/verify', ['challenge_id' => $challengeId, 'code' => $wrongCode, 'device_name' => 'mobile'])
        ->assertJsonPath('code', 'OTP_LOCKED');

    $this->postJson('/api/v1/auth/email/verify', ['challenge_id' => $challengeId, 'code' => $code, 'device_name' => 'mobile'])
        ->assertJsonPath('code', 'OTP_LOCKED');

    expect(User::query()->sole()->status->value)->toBe('pending_email_verification');
});

it('rejects an expired code and accepts each code only once', function () {
    $challengeId = register(naturalPersonPayload())->json('data.verification.challenge_id');
    $code = lastOtpCode();
    $payload = ['challenge_id' => $challengeId, 'code' => $code, 'device_name' => 'mobile'];

    $this->travel(301)->seconds();
    $this->postJson('/api/v1/auth/email/verify', $payload)->assertJsonPath('code', 'OTP_INVALID');

    $this->travelBack();
    OtpChallenge::query()->whereKey($challengeId)->update(['expires_at' => now()->addMinutes(5)]);

    $this->postJson('/api/v1/auth/email/verify', $payload)->assertOk();
    $this->postJson('/api/v1/auth/email/verify', $payload)->assertJsonPath('code', 'OTP_INVALID');
});

it('resends a code after the cooldown and invalidates the previous one', function () {
    $challengeId = register(naturalPersonPayload())->json('data.verification.challenge_id');
    $firstCode = lastOtpCode();

    $this->postJson('/api/v1/auth/otp/resend', ['challenge_id' => $challengeId])
        ->assertStatus(429)
        ->assertJsonPath('code', 'OTP_RESEND_TOO_SOON');

    $this->travel(61)->seconds();

    $newChallengeId = $this->postJson('/api/v1/auth/otp/resend', ['challenge_id' => $challengeId])
        ->assertCreated()
        ->json('data.challenge_id');

    $this->postJson('/api/v1/auth/email/verify', ['challenge_id' => $challengeId, 'code' => $firstCode, 'device_name' => 'mobile'])
        ->assertJsonPath('code', 'OTP_INVALID');

    $this->postJson('/api/v1/auth/email/verify', ['challenge_id' => $newChallengeId, 'code' => lastOtpCode(), 'device_name' => 'mobile'])
        ->assertOk();
});

it('caps the number of codes sent per hour', function () {
    $challengeId = register(naturalPersonPayload())->json('data.verification.challenge_id');
    app(SettingsService::class)->set('otp.max_sends_per_hour', 2, 'Test du plafond');

    $this->travel(61)->seconds();
    $challengeId = $this->postJson('/api/v1/auth/otp/resend', ['challenge_id' => $challengeId])->assertCreated()->json('data.challenge_id');

    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/otp/resend', ['challenge_id' => $challengeId])
        ->assertStatus(429)
        ->assertJsonPath('code', 'OTP_SEND_LIMIT_REACHED');
});

it('confirms that a free e-mail and Pernum can be used', function () {
    $this->postJson('/api/v1/auth/register/check', ['email' => 'Libre@Example.com', 'pernum' => '1234567890'])
        ->assertOk()
        ->assertJsonPath('data.available', true);
});

it('reports an e-mail or a Pernum that is already taken before the form goes further', function () {
    User::factory()->create(['email' => 'pris@example.com']);

    $this->postJson('/api/v1/auth/register/check', ['email' => 'PRIS@example.com', 'pernum' => '1234567890'])
        ->assertUnprocessable()
        ->assertJsonStructure(['details' => ['fields' => ['email']]]);

    $this->postJson('/api/v1/auth/register/check', ['email' => 'libre@example.com', 'pernum' => 'x'])
        ->assertUnprocessable()
        ->assertJsonStructure(['details' => ['fields' => ['pernum']]]);
});
