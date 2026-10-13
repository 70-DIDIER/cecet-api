<?php

use App\Domain\Access\Actions\SyncAccessCatalog;
use App\Domain\Access\PermissionCatalog;
use App\Domain\Catalog\CatalogManager;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Enums\PackPlanStatus;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Identity\Mail\OtpCodeMail;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Providers\Fake\FakePaymentProvider;
use App\Domain\Payout\Models\PayoutDestination;
use App\Domain\Settings\Actions\SyncSettingsCatalog;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Real concurrency needs committed data visible to other processes: these tests manage their own database state.
pest()->extend(TestCase::class)->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Mirrors the settings and access catalogs, as the seeders do in a real installation.
 */
function seedCatalogs(): void
{
    app(SyncSettingsCatalog::class)->handle();
    app(SyncAccessCatalog::class)->handle();
}

/**
 * Returns the code of the last OTP email queued (requires Mail::fake()).
 */
function lastOtpCode(?string $to = null): string
{
    $mail = Mail::queued(OtpCodeMail::class, fn (OtpCodeMail $mail) => $to === null || $mail->hasTo($to))->last();

    expect($mail)->not->toBeNull('No OTP email was queued.');

    return $mail->code;
}

/**
 * Issues a real Sanctum token, optionally with an open step-up window.
 * Resets the resolved guards so that the next request authenticates with this token.
 */
function tokenFor(User $user, bool $withStepUp = false): string
{
    app('auth')->forgetGuards();

    $token = $user->createToken('tests');

    if ($withStepUp) {
        $token->accessToken->forceFill(['step_up_until' => now()->addMinutes(10)])->save();
    }

    return $token->plainTextToken;
}

/**
 * @param  array<string, string>  $headers  lowercase header names as sent by the provider
 */
function sendFakeWebhook(string $body, array $headers): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', '/api/v1/webhooks/payments/fake', [], [], [], $server, $body);
}

/**
 * Moves the fake transaction of the attempt to a status and delivers the matching signed webhook.
 */
function settleFakeAttempt(string $attemptId, ProviderPaymentStatus $status, ?int $amount = null, ?string $eventId = null): TestResponse
{
    $attempt = PaymentAttempt::query()->findOrFail($attemptId);
    $webhook = app(FakePaymentProvider::class)->simulate((string) $attempt->provider_transaction_id, $status, $amount, $eventId);

    return sendFakeWebhook($webhook['body'], $webhook['headers']);
}

/**
 * @param  array<string, array<string, int>>  $rules
 */
function setFeeRules(array $rules): void
{
    app(SettingsService::class)->set('payment.customer_fees', $rules, 'Barème issu du contrat du prestataire');
}

/**
 * Terms of a periodic Vendeur pack: 12 monthly allowances of 125 YEM.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function periodicTerms(array $overrides = []): array
{
    return [
        'price_xof' => 100000,
        'period' => ['unit' => 'month', 'every' => 1, 'cycles' => 12],
        'quota_per_period' => '125',
        'cumulative' => true,
        'end_of_term' => 'expire',
        'reason' => 'Grille initiale ratifiée par le CA',
        ...$overrides,
    ];
}

/**
 * Terms of an on-demand Personnel pack: no period, no fixed quota.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function personnelTerms(array $overrides = []): array
{
    return ['price_xof' => 5000, 'reason' => 'Pack Personnel initial', ...$overrides];
}

/**
 * Creates a plan with one published version and publishes the plan (unless told otherwise).
 *
 * @param  array<string, mixed>|null  $terms
 */
function makePlan(PackFamily $family = PackFamily::Vendeur, string $code = 'V1', ?array $terms = null, bool $publish = true, bool $sellableImmediately = true): PackPlan
{
    $catalog = app(CatalogManager::class);
    $actor = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();

    $plan = $catalog->createPlan($actor, $family, $code, "Pack {$code}", null, null);
    $terms ??= $family === PackFamily::Personnel ? personnelTerms() : periodicTerms();

    // A Vendeur pack only credits its first allowance after a month; most scenarios need stock to sell straight away.
    if ($family === PackFamily::Vendeur && $sellableImmediately && ! isset($terms['rules']['first_credit_delay_periods'])) {
        $terms['rules']['first_credit_delay_periods'] = 0;
    }

    $version = $catalog->createVersion($actor, $plan, $terms);
    $catalog->publishVersion($actor, $plan, $version);

    if ($publish) {
        $catalog->transition($actor, $plan, PackPlanStatus::Published, 'Mise au catalogue');
    }

    return $plan->refresh();
}

/**
 * A member subscribes to a plan and the fake provider confirms the payment: returns the activated pack.
 */
function subscribeAndPay(User $member, PackPlan $plan): UserPack
{
    $response = test()->withToken(tokenFor($member))
        ->postJson('/api/v1/me/packs', ['plan_id' => $plan->id], ['Idempotency-Key' => (string) Str::ulid()]);

    $response->assertSuccessful();

    if ($response->json('data.payment') !== null) {
        settleFakeAttempt($response->json('data.payment.id'), ProviderPaymentStatus::Approved)->assertOk();
    }

    return UserPack::query()->findOrFail($response->json('data.user_pack.id'));
}

/**
 * A member able to sell YEM to the CECT: an active Pack Vendeur, a declared Pernum and a payout destination.
 *
 * @param  array<string, mixed>  $terms
 * @return array{0: User, 1: UserPack}
 */
function readySeller(array $terms = [], string $method = 'TMONEY'): array
{
    static $counter = 0;
    $counter++;

    $member = User::factory()->create();
    $member->pernumIdentities()->create(['pernum' => (string) (1001000000 + $counter), 'kind' => 'personal', 'status' => 'declared']);

    PayoutDestination::query()->create([
        'user_id' => $member->id,
        'method' => $method,
        'channel' => in_array($method, ['TMONEY', 'FLOOZ'], true) ? 'mobile_money' : 'manual',
        'country' => 'TG',
        'phone' => '+22890'.str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
    ]);

    $pack = subscribeAndPay($member, makePlan(code: "VS{$counter}", terms: periodicTerms($terms)));

    return [$member, $pack];
}

function configureCectPernum(string $pernum = '1000000001'): void
{
    app(SettingsService::class)->set('yem.cect_pernum', $pernum, 'Pernum de réception de la CECT');
}
