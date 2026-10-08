<?php

use App\Domain\Access\AccountManager;
use App\Domain\Access\PermissionCatalog;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Mail\OtpCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
| What the React site and the former scoopcectogo.com expect from authentication: login by Pernum or e-mail,
| HttpOnly cookie sessions for the site, the membership amount at registration, the former reset form.
*/

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->member = User::factory()->create();
    $this->member->pernumIdentities()->create(['pernum' => '1001043953', 'kind' => 'personal', 'status' => 'declared']);
});

/**
 * Headers of a request coming from the React site (a stateful Sanctum domain).
 *
 * @return array<string, string>
 */
function fromReactSite(): array
{
    return ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/connexion'];
}

describe('login by Pernum or e-mail', function () {
    it('accepts the Pernum, as on the former site, whatever its case', function (string $identifier) {
        $this->postJson('/api/v1/auth/login', ['identifier' => $identifier, 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->member->id);
    })->with(['exact Pernum' => '1001043953', 'Pernum with spaces' => ' 1001043953 ']);

    it('accepts the e-mail address through the same field', function () {
        $this->postJson('/api/v1/auth/login', ['identifier' => mb_strtoupper($this->member->email), 'password' => 'Password123'])->assertOk();
    });

    it('refuses a revoked Pernum with the generic message', function () {
        $this->member->pernumIdentities()->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->postJson('/api/v1/auth/login', ['identifier' => '1001043953', 'password' => 'Password123'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    });

    it('keeps the second factor of internal accounts behind the MFA route expected by the site', function () {
        $staff = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
        $challengeId = $this->postJson('/api/v1/auth/login', ['identifier' => $staff->email, 'password' => 'Password123'])->assertStatus(202)->json('data.challenge_id');

        $this->postJson('/api/v1/auth/mfa/verify', ['challenge_id' => $challengeId, 'code' => lastOtpCode($staff->email)])
            ->assertOk()
            ->assertJsonPath('data.user.roles', ['validateur']);
    });
});

describe('cookie sessions for the React site', function () {
    it('opens an HttpOnly session instead of returning a token', function () {
        $response = $this->withHeaders(fromReactSite())
            ->postJson('/api/v1/auth/login', ['identifier' => '1001043953', 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonPath('data.mode', 'session')
            ->assertJsonMissingPath('data.access_token');

        $this->assertAuthenticatedAs($this->member, 'web');
        expect($this->member->tokens()->count())->toBe(0);

        $this->withHeaders(fromReactSite())->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $this->member->id);

        $this->withHeaders(fromReactSite())->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest('web');
    });

    it('still gives a bearer token to other clients', function () {
        $this->postJson('/api/v1/auth/login', ['identifier' => '1001043953', 'password' => 'Password123'])
            ->assertJsonPath('data.mode', 'token')
            ->assertJsonStructure(['data' => ['access_token', 'expires_at']]);
    });

    it('confirms a step-up on the session itself', function () {
        $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
        $challengeId = $this->withHeaders(fromReactSite())->postJson('/api/v1/auth/login', ['identifier' => $admin->email, 'password' => 'Password123'])->json('data.challenge_id');
        $this->withHeaders(fromReactSite())->postJson('/api/v1/auth/mfa/verify', ['challenge_id' => $challengeId, 'code' => lastOtpCode($admin->email)])->assertJsonPath('data.mode', 'session');

        $update = fn () => $this->withHeaders(fromReactSite())->putJson('/api/v1/admin/settings/rates.sale_xof_per_yem', ['value' => 640, 'reason' => 'Ajustement du cours'], ['Idempotency-Key' => (string) Str::ulid()]);

        $update()->assertForbidden()->assertJsonPath('code', 'STEP_UP_REQUIRED');

        $this->travel(61)->seconds();
        $stepUp = $this->withHeaders(fromReactSite())->postJson('/api/v1/auth/step-up')->assertCreated()->json('data.challenge_id');
        $this->withHeaders(fromReactSite())->postJson('/api/v1/auth/step-up/verify', ['challenge_id' => $stepUp, 'code' => lastOtpCode($admin->email)])->assertOk();

        $update()->assertOk();
    });

    it('ends the cookie sessions of a suspended account', function () {
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $this->member->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

        app(AccountManager::class)->suspend(User::factory()->staff(PermissionCatalog::SUPERADMIN)->create(), $this->member, 'Fraude suspectée');

        expect(DB::table('sessions')->where('user_id', $this->member->id)->count())->toBe(0)
            ->and($this->member->refresh()->status)->toBe(UserStatus::Suspended);
    });
});

describe('registration as on the former form', function () {
    it('records the commitment, the place and date of signature, and returns the membership amount', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'person_type' => 'legal',
            'legal_name' => 'AL-IMANE SARL',
            'legal_form' => 'Auto-entrepreneur',
            'first_name' => 'Imane',
            'last_name' => 'Alassane',
            'pernum' => '1001292934',
            'phone' => '+22870623423',
            'email' => 'alimanesarl@example.com',
            'password' => 'Solide2026Pass',
            'password_confirmation' => 'Solide2026Pass',
            'city' => 'Lomé',
            'preferred_payment_method' => 'CASH',
            'commitment_accepted' => true,
            'signed_place' => 'Lomé',
            'signed_on' => now()->toDateString(),
        ], ['Idempotency-Key' => (string) Str::ulid()]);

        $response->assertCreated()
            ->assertJsonPath('data.membership_fee.amount', 200000)
            ->assertJsonPath('data.membership_fee.currency', 'XOF')
            ->assertJsonPath('data.membership_fee.payment_quote.total_amount', 200000);

        $user = User::query()->where('email', 'alimanesarl@example.com')->sole();
        expect($user->signed_place)->toBe('Lomé')
            ->and($user->preferred_payment_method)->toBe('CASH')
            ->and($user->commitment_accepted_at)->not->toBeNull()
            ->and($user->commitment_version)->toBe('statuts-ri-2026');
    });

    it('requires the commitment and the signature fields', function () {
        $this->postJson('/api/v1/auth/register', ['person_type' => 'natural'], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertStatus(422)
            ->assertJsonStructure(['details' => ['fields' => ['commitment_accepted', 'signed_place', 'signed_on']]]);
    });
});

describe('password reset as on the former form', function () {
    it('sends a code only when the Pernum belongs to the account of the e-mail', function () {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $this->member->email, 'pernum' => '9999999999'])->assertStatus(202);
        Mail::assertNothingQueued();

        $this->postJson('/api/v1/auth/password/forgot', ['email' => $this->member->email, 'pernum' => '1001043953'])->assertStatus(202);
        Mail::assertQueued(OtpCodeMail::class);
    });
});

describe('public pack catalog', function () {
    it('is readable without an account, and personalised for a signed-in member', function () {
        makePlan(code: 'V1');

        $this->getJson('/api/v1/packs')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'V1')
            ->assertJsonPath('data.0.payment_quote', null)
            ->assertJsonPath('data.0.eligible', null);

        $this->withToken(tokenFor($this->member))->getJson('/api/v1/packs')
            ->assertJsonPath('data.0.eligible', true)
            ->assertJsonPath('data.0.payment_quote.total_amount', 100000);
    });
});
