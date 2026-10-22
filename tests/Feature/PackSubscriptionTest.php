<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\CatalogManager;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Enums\PackPlanStatus;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Quota\Models\QuotaLedgerEntry;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->member = User::factory()->create();
});

function subscribeTo(User $member, string $planId): TestResponse
{
    return test()->withToken(tokenFor($member))
        ->postJson('/api/v1/me/packs', ['plan_id' => $planId], ['Idempotency-Key' => (string) Str::ulid()]);
}

describe('catalog as seen by a member', function () {
    it('shows members only the published packs allowed for their person type', function () {
        setFeeRules(['fake' => ['rate_bps' => 300, 'fixed_xof' => 0, 'rounding_xof' => 1]]);
        app(SettingsService::class)->set('payment.customer_bears_fees', true, 'Frais à la charge du client pour ce scénario');
        makePlan(code: 'V1');
        $merchant = makePlan(PackFamily::Marchand, 'M1');
        makePlan(code: 'V9', publish: false);

        $response = $this->withToken(tokenFor($this->member))->getJson('/api/v1/packs')->assertOk()->assertJsonCount(1, 'data');
        $byCode = collect($response->json('data'))->keyBy('code');

        expect($byCode->keys()->all())->toEqualCanonicalizing(['V1'])
            ->and($byCode['V1']['eligible'])->toBeTrue()
            ->and($byCode['V1']['payment_quote'])->toBe(['net_amount' => 100000, 'fee_amount' => 3093, 'total_amount' => 103093]);

        $this->withToken(tokenFor($this->member))->getJson("/api/v1/packs/{$merchant->id}")->assertNotFound();

        $legal = User::factory()->legalPerson()->create();
        $legalResponse = $this->withToken(tokenFor($legal))->getJson('/api/v1/packs')->assertOk()->assertJsonCount(1, 'data');
        expect(collect($legalResponse->json('data'))->pluck('code')->all())->toEqualCanonicalizing(['M1']);
        $this->withToken(tokenFor($legal))->getJson("/api/v1/packs/{$merchant->id}")->assertOk();
        $this->withToken(tokenFor($legal))->getJson("/api/v1/packs/{$byCode['V1']['id']}")->assertNotFound();
    });

    it('provides an authenticated catalog that never exposes ineligible packs', function () {
        makePlan(code: 'V1');
        makePlan(PackFamily::Marchand, 'M1');

        $this->getJson('/api/v1/me/catalog-packs')->assertUnauthorized();

        $natural = $this->withToken(tokenFor($this->member))->getJson('/api/v1/me/catalog-packs')->assertOk();
        expect(collect($natural->json('data'))->pluck('code')->all())->toEqualCanonicalizing(['V1']);

        $legal = User::factory()->legalPerson()->create();
        $legalCatalog = $this->withToken(tokenFor($legal))->getJson('/api/v1/me/catalog-packs')->assertOk();
        expect(collect($legalCatalog->json('data'))->pluck('code')->all())->toEqualCanonicalizing(['M1']);
    });

    it('hides unpublished plans', function () {
        $draft = makePlan(code: 'V9', publish: false);

        $this->withToken(tokenFor($this->member))->getJson("/api/v1/packs/{$draft->id}")->assertNotFound();
    });
});

describe('subscription', function () {
    it('starts a payment for the version price and activates the pack only once the provider confirms', function () {
        $plan = makePlan();

        $response = subscribeTo($this->member, $plan->id)
            ->assertCreated()
            ->assertJsonPath('data.user_pack.status', 'pending_payment')
            ->assertJsonPath('data.user_pack.price_xof', 100000)
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.amount', 100000)
            ->assertJsonPath('data.user_pack.balance', null);

        $packId = $response->json('data.user_pack.id');
        expect(UserPack::query()->findOrFail($packId)->status)->toBe(UserPackStatus::PendingPayment);

        settleFakeAttempt($response->json('data.payment.id'), ProviderPaymentStatus::Approved)->assertOk();

        $pack = UserPack::query()->with(['account', 'periods'])->findOrFail($packId);
        expect($pack->status)->toBe(UserPackStatus::Active)
            ->and($pack->activated_at)->not->toBeNull()
            ->and($pack->paid_at)->not->toBeNull()
            ->and($pack->periods)->toHaveCount(12)
            ->and($pack->expires_at->toDateString())->toBe(now()->addMonths(12)->toDateString())
            ->and($pack->account->available)->toBe('125.000000')
            ->and($pack->account->reserved)->toBe('0.000000')
            ->and(QuotaLedgerEntry::query()->where('user_pack_id', $packId)->count())->toBe(1);

        $audit = AuditEvent::query()->where('action', 'packs.activated')->sole();
        expect($audit->metadata)->toMatchArray(['plan_code' => 'V1', 'version' => 1, 'price_xof' => 100000]);
    });

    it('adds the transaction fees to the payment and checks the total before activating', function () {
        setFeeRules(['fake' => ['rate_bps' => 300, 'fixed_xof' => 0, 'rounding_xof' => 1]]);
        app(SettingsService::class)->set('payment.customer_bears_fees', true, 'Frais à la charge du client pour ce scénario');
        $plan = makePlan();

        $response = subscribeTo($this->member, $plan->id)
            ->assertCreated()
            ->assertJsonPath('data.payment.net_amount', 100000)
            ->assertJsonPath('data.payment.fee_amount', 3093)
            ->assertJsonPath('data.payment.amount', 103093);

        $attemptId = $response->json('data.payment.id');
        settleFakeAttempt($attemptId, ProviderPaymentStatus::Approved, amount: 100000)->assertOk();

        expect(UserPack::query()->sole()->status)->toBe(UserPackStatus::PendingPayment)
            ->and(PaymentAttempt::query()->findOrFail($attemptId)->status)->toBe(PaymentAttemptStatus::Mismatch);
    });

    it('is not idempotent-blind: settling the same payment twice activates the pack once', function () {
        $plan = makePlan();
        $paymentId = subscribeTo($this->member, $plan->id)->json('data.payment.id');

        settleFakeAttempt($paymentId, ProviderPaymentStatus::Approved)->assertOk();
        settleFakeAttempt($paymentId, ProviderPaymentStatus::Approved)->assertOk();

        expect(AuditEvent::query()->where('action', 'packs.activated')->count())->toBe(1)
            ->and(QuotaLedgerEntry::query()->count())->toBe(1);
    });

    it('returns the same pack and checkout when the member subscribes again before paying', function () {
        $plan = makePlan();

        $first = subscribeTo($this->member, $plan->id)->assertCreated();
        $second = subscribeTo($this->member, $plan->id)->assertOk();

        expect($second->json('data.user_pack.id'))->toBe($first->json('data.user_pack.id'))
            ->and($second->json('data.payment.id'))->toBe($first->json('data.payment.id'))
            ->and(UserPack::query()->count())->toBe(1);
    });

    it('refuses plans that are not published, and members without an active membership', function () {
        $draft = makePlan(code: 'V8', publish: false);
        $suspended = makePlan(code: 'V9');
        app(CatalogManager::class)->transition(User::factory()->staff('superadmin')->create(), $suspended, PackPlanStatus::Suspended, 'Vérification des taux');

        subscribeTo($this->member, $draft->id)->assertStatus(409)->assertJsonPath('code', 'PACK_NOT_AVAILABLE');
        subscribeTo($this->member, $suspended->id)->assertStatus(409)->assertJsonPath('code', 'PACK_NOT_AVAILABLE');

        $unpaid = User::factory()->status(UserStatus::PendingPayment)->create();
        subscribeTo($unpaid, makePlan(code: 'V1')->id)->assertForbidden()->assertJsonPath('code', 'HTTP_403');

        subscribeTo(User::factory()->staff(PermissionCatalog::ADMIN)->create(), $suspended->id)->assertForbidden();
    });

    it('opens the Marchand pack to legal persons only', function () {
        $plan = makePlan(PackFamily::Marchand, 'M1');

        subscribeTo($this->member, $plan->id)->assertStatus(422)->assertJsonPath('code', 'PACK_NOT_ELIGIBLE')->assertJsonPath('details.eligible_person_types', ['legal']);

        subscribeTo(User::factory()->legalPerson()->create(), $plan->id)->assertCreated();
    });

    it('refuses seller, consumer and personnel subscriptions by a legal person including legacy catalog metadata', function () {
        $legal = User::factory()->legalPerson()->create();
        foreach ([PackFamily::Vendeur, PackFamily::Consommateur, PackFamily::Personnel] as $family) {
            $plan = makePlan($family, $family->value[0].Str::upper(Str::random(3)));
            subscribeTo($legal, $plan->id)->assertStatus(422)
                ->assertJsonPath('code', 'PACK_NOT_ELIGIBLE')
                ->assertJsonPath('details.eligible_person_types', ['natural']);
        }
    });

    it('activates a free pack immediately without any payment', function () {
        $plan = makePlan(terms: periodicTerms(['price_xof' => 0]));

        $response = subscribeTo($this->member, $plan->id)
            ->assertCreated()
            ->assertJsonPath('data.user_pack.status', 'active')
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.user_pack.balance.available', '125.000000');

        expect(PaymentIntent::query()->count())->toBe(0)
            ->and($response->json('data.user_pack.paid_at'))->toBeNull();
    });
});

describe('seller subscriptions limit, consumer independence and duplicate seller formulas', function () {
    it('counts only Vendeur subscriptions, including pending and active, not Consommateur or Personnel', function () {
        $v1 = makePlan(code: 'V1');
        $v2 = makePlan(code: 'V2');
        $v3 = makePlan(code: 'V3');
        $consumer = makePlan(PackFamily::Consommateur, 'C1');
        $personnel = makePlan(PackFamily::Personnel, 'P1');

        subscribeTo($this->member, $v1->id)->assertCreated();
        subscribeTo($this->member, $consumer->id)->assertCreated();
        subscribeTo($this->member, $v2->id)->assertCreated();
        subscribeTo($this->member, $v3->id)->assertStatus(409)
            ->assertJsonPath('code', 'PACK_LIMIT_REACHED');
        subscribeTo($this->member, $personnel->id)->assertCreated();
        subscribeTo($this->member, makePlan(PackFamily::Consommateur, 'C2')->id)->assertCreated();
    });

    it('accepts two active Vendeur contracts of the same formula and a fresh Consommateur contract each time', function () {
        $seller = makePlan(code: 'V1');
        $consumer = makePlan(PackFamily::Consommateur, 'C1');
        $a = subscribeAndPay($this->member, $seller);
        $b = subscribeAndPay($this->member, $seller);
        expect($a->id)->not->toBe($b->id);
        subscribeTo($this->member, makePlan(code: 'V2')->id)->assertStatus(409);
        $c1 = subscribeAndPay($this->member, $consumer);
        $c2 = subscribeAndPay($this->member, $consumer);
        expect($c1->id)->not->toBe($c2->id)
            ->and(UserPack::query()->where('user_id', $this->member->id)->where('family', PackFamily::Vendeur)->where('status', UserPackStatus::Active)->count())->toBe(2);
    });

    it('follows a SuperAdmin-configured seller-only slot limit', function () {
        $settings = app(SettingsService::class);
        $plans = [makePlan(code: 'V1'), makePlan(code: 'V2'), makePlan(code: 'V3')];
        $settings->set('packs.max_structured_active', 3, 'Autorise trois souscriptions Vendeur');
        foreach ($plans as $plan) {
            subscribeTo($this->member, $plan->id)->assertCreated();
        }
        $settings->set('packs.max_structured_active', 1, 'Retour à un seul pack Vendeur');
        subscribeTo($this->member, makePlan(code: 'V4')->id)->assertStatus(409);
        $settings->set('packs.families_excluded_from_limit', ['PERSONNEL', 'VENDEUR'], 'Compatibilité historique');
        subscribeTo($this->member, makePlan(code: 'V5')->id)->assertStatus(409);
        subscribeTo($this->member, makePlan(PackFamily::Consommateur, 'C1')->id)->assertCreated();
    });

    it('frees one Vendeur slot after stale unpaid pack cancellation', function () {
        $settings = app(SettingsService::class);
        $settings->set('packs.max_structured_active', 1, 'Un seul pack Vendeur');
        $first = makePlan(code: 'V1');
        $second = makePlan(code: 'V2');

        subscribeTo($this->member, $first->id)->assertCreated();
        subscribeTo($this->member, $second->id)->assertStatus(409);

        $this->travel(25)->hours();
        $this->artisan('packs:cancel-stale-pending')->expectsOutputToContain('1 pack(s)')->assertSuccessful();
        subscribeTo($this->member, $second->id)->assertCreated();
    });
});

describe('purchased conditions', function () {
    it('keeps the exact version and price of a pack when the catalog changes (AC-06)', function () {
        $plan = makePlan(code: 'V1');
        $pack = subscribeAndPay($this->member, $plan);

        $actor = User::factory()->staff('superadmin')->create();
        $catalog = app(CatalogManager::class);
        $newVersion = $catalog->createVersion($actor, $plan, periodicTerms(['price_xof' => 150000, 'quota_per_period' => '200', 'cumulative' => false]));
        $catalog->publishVersion($actor, $plan, $newVersion);
        $catalog->transition($actor, $plan, PackPlanStatus::Retired, 'Remplacé par une nouvelle grille');

        $pack->refresh()->load('version');
        expect($pack->version->version)->toBe(1)
            ->and($pack->price_xof)->toBe(100000)
            ->and($pack->version->quota_per_period)->toBe('125.000000')
            ->and($pack->version->cumulative)->toBeTrue()
            ->and($pack->status)->toBe(UserPackStatus::Active);

        $this->withToken(tokenFor($this->member))->getJson("/api/v1/me/packs/{$pack->id}")
            ->assertOk()
            ->assertJsonPath('data.version.version', 1)
            ->assertJsonPath('data.version.price_xof', 100000);
    });

    it('sells new subscribers the latest published version', function () {
        $plan = makePlan(code: 'V1');
        $actor = User::factory()->staff('superadmin')->create();
        $catalog = app(CatalogManager::class);
        $catalog->publishVersion($actor, $plan, $catalog->createVersion($actor, $plan, periodicTerms(['price_xof' => 150000])));

        subscribeTo($this->member, $plan->id)
            ->assertCreated()
            ->assertJsonPath('data.user_pack.price_xof', 150000)
            ->assertJsonPath('data.user_pack.version.version', 2)
            ->assertJsonPath('data.payment.amount', 150000);
    });

    it('moves an unpaid pack to the latest version once nothing is awaiting payment', function () {
        $plan = makePlan(code: 'V1');
        $first = subscribeTo($this->member, $plan->id)->assertCreated();
        settleFakeAttempt($first->json('data.payment.id'), ProviderPaymentStatus::Declined)->assertOk();

        $actor = User::factory()->staff('superadmin')->create();
        $catalog = app(CatalogManager::class);
        $catalog->publishVersion($actor, $plan, $catalog->createVersion($actor, $plan, periodicTerms(['price_xof' => 90000])));

        $second = subscribeTo($this->member, $plan->id)
            ->assertCreated()
            ->assertJsonPath('data.user_pack.id', $first->json('data.user_pack.id'))
            ->assertJsonPath('data.user_pack.price_xof', 90000)
            ->assertJsonPath('data.payment.amount', 90000);

        settleFakeAttempt($second->json('data.payment.id'), ProviderPaymentStatus::Approved)->assertOk();
        expect(UserPack::query()->sole()->version->version)->toBe(2);
    });
});

describe('unpaid packs', function () {
    it('leaves a recent unpaid pack alone, then cancels it with its payment once the delay has passed', function () {
        $plan = makePlan();
        $attemptId = subscribeTo($this->member, $plan->id)->assertCreated()->json('data.payment.id');

        $this->travel(2)->hours();
        $this->artisan('packs:cancel-stale-pending')->expectsOutputToContain('0 pack(s)')->assertSuccessful();
        expect(UserPack::query()->sole()->status)->toBe(UserPackStatus::PendingPayment);

        $this->travel(23)->hours();
        $this->artisan('packs:cancel-stale-pending')->expectsOutputToContain('1 pack(s)')->assertSuccessful();

        expect(UserPack::query()->sole()->status)->toBe(UserPackStatus::Canceled)
            ->and(UserPack::query()->sole()->canceled_at)->not->toBeNull()
            ->and(PaymentIntent::query()->sole()->status->value)->toBe('canceled')
            ->and(PaymentAttempt::query()->findOrFail($attemptId)->status)->toBe(PaymentAttemptStatus::Expired)
            ->and(AuditEvent::query()->where('action', 'packs.canceled_unpaid')->exists())->toBeTrue();
    });

    it('flags a payment received for an already canceled pack instead of activating it', function () {
        $plan = makePlan();
        $attemptId = subscribeTo($this->member, $plan->id)->json('data.payment.id');

        $this->travel(25)->hours();
        $this->artisan('packs:cancel-stale-pending')->assertSuccessful();

        $pack = UserPack::query()->sole();
        expect($pack->status)->toBe(UserPackStatus::Canceled);

        settleFakeAttempt($attemptId, ProviderPaymentStatus::Approved)->assertOk();

        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        expect($pack->refresh()->status)->toBe(UserPackStatus::Canceled)
            ->and($attempt->requires_review)->toBeTrue()
            ->and($attempt->review_reason)->toBe('payment_on_canceled_intent')
            ->and(QuotaLedgerEntry::query()->count())->toBe(0);
    });

    it('lets the member subscribe again after cancellation', function () {
        $plan = makePlan();
        subscribeTo($this->member, $plan->id)->assertCreated();

        $this->travel(25)->hours();
        $this->artisan('packs:cancel-stale-pending')->assertSuccessful();

        subscribeTo($this->member, $plan->id)->assertCreated();
        expect(UserPack::query()->count())->toBe(2);
    });
});

describe('privacy', function () {
    it('shows a member only their own packs and ledger', function () {
        $plan = makePlan();
        $mine = subscribeAndPay($this->member, $plan);
        $stranger = User::factory()->create();
        $theirs = subscribeAndPay($stranger, $plan);

        $this->withToken(tokenFor($this->member))->getJson('/api/v1/me/packs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->withToken(tokenFor($this->member))->getJson("/api/v1/me/packs/{$theirs->id}")->assertNotFound();

        $ledger = $this->withToken(tokenFor($this->member))->getJson('/api/v1/me/quota-ledger')->assertOk();
        expect(collect($ledger->json('data'))->pluck('user_pack_id')->unique()->all())->toBe([$mine->id]);

        $this->withToken(tokenFor($this->member))->getJson("/api/v1/me/quota-ledger?user_pack_id={$theirs->id}")->assertOk()->assertJsonCount(0, 'data');
    });
});
