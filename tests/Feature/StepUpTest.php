<?php

use App\Domain\Access\PermissionCatalog;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->admin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
});

function updateSalesRate(string $token, int $value = 640): TestResponse
{
    return test()->withToken($token)->putJson('/api/v1/admin/settings/rates.sale_xof_per_yem', [
        'value' => $value,
        'reason' => 'Ajustement du cours de vente',
    ], ['Idempotency-Key' => 'step-up-'.$value.'-'.uniqid()]);
}

it('requires a step-up before a sensitive action', function () {
    updateSalesRate(tokenFor($this->admin))
        ->assertForbidden()
        ->assertJsonPath('code', 'STEP_UP_REQUIRED');
});

it('opens a limited step-up window after confirming the email code', function () {
    $token = tokenFor($this->admin);

    $challengeId = $this->withToken($token)->postJson('/api/v1/auth/step-up')
        ->assertCreated()
        ->assertJsonPath('data.purpose', 'step_up')
        ->json('data.challenge_id');

    $this->withToken($token)->postJson('/api/v1/auth/step-up/verify', ['challenge_id' => $challengeId, 'code' => lastOtpCode()])
        ->assertOk()
        ->assertJsonStructure(['data' => ['step_up_until']]);

    updateSalesRate($token)->assertOk();

    $this->travel(601)->seconds();
    updateSalesRate($token, 630)->assertJsonPath('code', 'STEP_UP_REQUIRED');
});

it('binds the step-up to the session that confirmed it', function () {
    $confirmedToken = tokenFor($this->admin);
    $otherToken = tokenFor($this->admin);

    $challengeId = $this->withToken($confirmedToken)->postJson('/api/v1/auth/step-up')->json('data.challenge_id');
    $this->withToken($confirmedToken)->postJson('/api/v1/auth/step-up/verify', ['challenge_id' => $challengeId, 'code' => lastOtpCode()])->assertOk();

    app('auth')->forgetGuards();
    updateSalesRate($otherToken)->assertJsonPath('code', 'STEP_UP_REQUIRED');
});

it('refuses a step-up code issued to another account', function () {
    $other = User::factory()->staff(PermissionCatalog::ADMIN)->create();

    $challengeId = $this->withToken(tokenFor($other))->postJson('/api/v1/auth/step-up')->json('data.challenge_id');
    $code = lastOtpCode($other->email);

    $this->withToken(tokenFor($this->admin))->postJson('/api/v1/auth/step-up/verify', ['challenge_id' => $challengeId, 'code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('code', 'OTP_INVALID');
});
