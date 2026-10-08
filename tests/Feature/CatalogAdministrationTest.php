<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Catalog\Models\PackVersion;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Database\Seeders\LegacyCatalogSeeder;
use Database\Seeders\VendeurDraftCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    $this->token = tokenFor($this->admin, withStepUp: true);
});

function adminPost(string $token, string $uri, array $payload = []): TestResponse
{
    return test()->withToken($token)->postJson($uri, $payload, ['Idempotency-Key' => (string) Str::ulid()]);
}

function createDraftPlan(string $token, string $family = 'VENDEUR', string $code = 'V1'): PackPlan
{
    $id = adminPost($token, '/api/v1/admin/packs/plans', ['family' => $family, 'code' => $code, 'name' => "Pack {$code}", 'level' => 1])
        ->assertCreated()
        ->json('data.id');

    return PackPlan::query()->findOrFail($id);
}

describe('plans', function () {
    it('creates a plan in draft with no terms and audits it', function () {
        adminPost($this->token, '/api/v1/admin/packs/plans', ['family' => 'CONSOMMATEUR', 'code' => 'C1', 'name' => 'Consommateur 1', 'level' => 1])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.current_version', null);

        expect(AuditEvent::query()->where('action', 'packs.plan_created')->sole()->after['code'])->toBe('C1');
    });

    it('refuses duplicate codes and malformed input', function (array $payload, int $status) {
        createDraftPlan($this->token, 'VENDEUR', 'V1');

        adminPost($this->token, '/api/v1/admin/packs/plans', $payload)->assertStatus($status);
    })->with([
        'duplicate code' => [['family' => 'VENDEUR', 'code' => 'V1', 'name' => 'Autre'], 409],
        'lowercase code' => [['family' => 'VENDEUR', 'code' => 'v2', 'name' => 'Autre'], 422],
        'unknown family' => [['family' => 'CAPITAINE', 'code' => 'X1', 'name' => 'Autre'], 422],
        'missing name' => [['family' => 'VENDEUR', 'code' => 'V2'], 422],
    ]);

    it('requires the permission and a recent step-up to change the catalog', function () {
        $validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();

        adminPost(tokenFor($validator, withStepUp: true), '/api/v1/admin/packs/plans', ['family' => 'VENDEUR', 'code' => 'V1', 'name' => 'Pack'])->assertForbidden();
        adminPost(tokenFor($this->admin), '/api/v1/admin/packs/plans', ['family' => 'VENDEUR', 'code' => 'V1', 'name' => 'Pack'])
            ->assertForbidden()
            ->assertJsonPath('code', 'STEP_UP_REQUIRED');

        $this->withToken(tokenFor($validator))->getJson('/api/v1/admin/packs/plans')->assertForbidden();
    });

    it('lists and filters plans with their current version', function () {
        makePlan(code: 'V1');
        makePlan(PackFamily::Personnel, 'P1');
        makePlan(code: 'V2', publish: false);

        $this->withToken($this->token)->getJson('/api/v1/admin/packs/plans?family=VENDEUR&status=published')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'V1')
            ->assertJsonPath('data.0.current_version.version', 1);

        $this->withToken($this->token)->getJson('/api/v1/admin/packs/plans?search=p1')->assertJsonCount(1, 'data');
    });
});

describe('versions', function () {
    it('validates the terms according to the family', function (string $family, array $terms, string $field) {
        $plan = createDraftPlan($this->token, $family, 'X1');

        adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/versions", $terms)
            ->assertStatus(422)
            ->assertJsonStructure(['details' => ['fields' => [$field]]]);
    })->with([
        'periodic pack without period' => ['VENDEUR', [...periodicTerms(), 'period' => null], 'period'],
        'periodic pack without end-of-term decision' => ['VENDEUR', Arr::except(periodicTerms(), 'end_of_term'), 'end_of_term'],
        'periodic pack with zero quota' => ['VENDEUR', periodicTerms(['quota_per_period' => '0']), 'quota_per_period'],
        'quota with 7 decimals' => ['VENDEUR', periodicTerms(['quota_per_period' => '1.1234567']), 'quota_per_period'],
        'negative price' => ['VENDEUR', periodicTerms(['price_xof' => -1]), 'price_xof'],
        'unknown period unit' => ['VENDEUR', periodicTerms(['period' => ['unit' => 'year', 'every' => 1, 'cycles' => 1]]), 'period.unit'],
        'max operation above quota' => ['VENDEUR', periodicTerms(['max_operation' => '999']), 'max_operation'],
        'max below min' => ['VENDEUR', periodicTerms(['min_operation' => '50', 'max_operation' => '10']), 'max_operation'],
        'personnel with a period' => ['PERSONNEL', personnelTerms(['period' => ['unit' => 'month', 'every' => 1, 'cycles' => 1]]), 'period'],
        'personnel with a quota' => ['PERSONNEL', personnelTerms(['quota_per_period' => '10']), 'quota_per_period'],
        'rule of another family' => ['VENDEUR', periodicTerms(['rules' => ['discount_bps' => 300]]), 'rules.discount_bps'],
        'discount above 100 %' => ['CONSOMMATEUR', periodicTerms(['rules' => ['discount_bps' => 20000]]), 'rules.discount_bps'],
        'missing reason' => ['VENDEUR', Arr::except(periodicTerms(), 'reason'), 'reason'],
    ]);

    it('creates versions numbered from 1, unpublished, with normalized decimals', function () {
        $plan = createDraftPlan($this->token);

        $first = adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/versions", periodicTerms(['quota_per_period' => 125.5]))
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.published_at', null)
            ->assertJsonPath('data.quota_per_period', '125.500000')
            ->assertJsonPath('data.total_quota', '1506.000000')
            ->assertJsonPath('data.eligible_person_types', ['natural', 'legal']);

        adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/versions", periodicTerms())->assertJsonPath('data.version', 2);

        expect($first->json('data.reason'))->toBe('Grille initiale ratifiée par le CA');
    });

    it('opens the Marchand family to legal persons only by default, and accepts family rules', function () {
        $merchant = createDraftPlan($this->token, 'MARCHAND', 'M1');
        adminPost($this->token, "/api/v1/admin/packs/plans/{$merchant->id}/versions", periodicTerms())
            ->assertCreated()
            ->assertJsonPath('data.eligible_person_types', ['legal']);

        $consumer = createDraftPlan($this->token, 'CONSOMMATEUR', 'C1');
        adminPost($this->token, "/api/v1/admin/packs/plans/{$consumer->id}/versions", periodicTerms(['rules' => ['discount_bps' => 300, 'reference_value_xof' => 300000]]))
            ->assertCreated()
            ->assertJsonPath('data.rules.discount_bps', 300);
    });

    it('freezes a version once published and offers the highest published version', function () {
        $plan = createDraftPlan($this->token);
        $v1 = adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/versions", periodicTerms())->json('data.id');
        $v2 = adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/versions", periodicTerms(['price_xof' => 120000]))->json('data.id');

        adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/transition", ['to' => 'published', 'reason' => 'Mise au catalogue'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PACK_HAS_NO_PUBLISHED_VERSION');

        adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/versions/{$v1}/publish")->assertOk()->assertJsonPath('data.version', 1);
        adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/versions/{$v1}/publish")->assertStatus(409)->assertJsonPath('code', 'PACK_VERSION_PUBLISHED');

        $this->withToken($this->token)->getJson("/api/v1/admin/packs/plans/{$plan->id}")->assertJsonPath('data.current_version.id', $v1);

        adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/versions/{$v2}/publish")->assertOk();
        $this->withToken($this->token)->getJson("/api/v1/admin/packs/plans/{$plan->id}")
            ->assertJsonPath('data.current_version.id', $v2)
            ->assertJsonPath('data.current_version.price_xof', 120000)
            ->assertJsonCount(2, 'data.versions');

        expect(PackVersion::query()->findOrFail($v1)->price_xof)->toBe(100000);
    });

    it('enforces the immutability of a published version in the database itself', function () {
        $plan = makePlan();
        $version = $plan->currentVersion;

        $attempt = fn (Closure $statement) => fn () => DB::transaction($statement);

        expect($attempt(fn () => DB::table('pack_versions')->where('id', $version->id)->update(['price_xof' => 1])))->toThrow(QueryException::class)
            ->and($attempt(fn () => DB::table('pack_versions')->where('id', $version->id)->update(['quota_per_period' => '999'])))->toThrow(QueryException::class)
            ->and($attempt(fn () => DB::table('pack_versions')->where('id', $version->id)->delete()))->toThrow(QueryException::class)
            ->and($version->fresh()->price_xof)->toBe(100000);
    });

    it('lets an unpublished version be discarded but never a published one', function () {
        $plan = createDraftPlan($this->token);
        $draft = adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/versions", periodicTerms())->json('data.id');

        $this->withToken($this->token)->deleteJson("/api/v1/admin/packs/plans/{$plan->id}/versions/{$draft}")->assertNoContent();
        expect(PackVersion::query()->count())->toBe(0);

        $published = makePlan(code: 'V9');
        $this->withToken($this->token)->deleteJson("/api/v1/admin/packs/plans/{$published->id}/versions/{$published->currentVersion->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PACK_VERSION_PUBLISHED');
    });

    it('rejects a version that belongs to another plan', function () {
        $a = makePlan(code: 'V1');
        $b = makePlan(code: 'V2');

        adminPost($this->token, "/api/v1/admin/packs/plans/{$a->id}/versions/{$b->currentVersion->id}/publish")->assertNotFound();
    });
});

describe('lifecycle', function () {
    it('walks a plan through its lifecycle with an audit trail', function () {
        $plan = makePlan(publish: false);
        $uri = "/api/v1/admin/packs/plans/{$plan->id}/transition";

        adminPost($this->token, $uri, ['to' => 'published', 'reason' => 'Ratifié par le CA'])->assertOk()->assertJsonPath('data.status', 'published');
        adminPost($this->token, $uri, ['to' => 'suspended', 'reason' => 'Vérification des taux'])->assertOk()->assertJsonPath('data.status', 'suspended');
        adminPost($this->token, $uri, ['to' => 'published', 'reason' => 'Taux confirmés'])->assertOk();
        adminPost($this->token, $uri, ['to' => 'retired', 'reason' => 'Remplacé par V2'])->assertOk();
        adminPost($this->token, $uri, ['to' => 'archived', 'reason' => 'Plus aucun détenteur'])->assertOk()->assertJsonPath('data.status', 'archived');

        expect(AuditEvent::query()->where('subject_id', $plan->id)->where('action', 'like', 'packs.plan_%')->count())->toBeGreaterThanOrEqual(5);
    });

    it('refuses impossible transitions and changes on archived plans', function (string $from, string $to) {
        $plan = makePlan(publish: false);
        $plan->forceFill(['status' => $from])->save();

        adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/transition", ['to' => $to, 'reason' => 'Test de transition'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PACK_TRANSITION_INVALID');
    })->with([
        'draft to suspended' => ['draft', 'suspended'],
        'published to draft' => ['published', 'draft'],
        'retired back to published' => ['retired', 'published'],
        'archived to anything' => ['archived', 'published'],
    ]);

    it('forbids adding a version to an archived plan', function () {
        $plan = makePlan();
        $plan->forceFill(['status' => 'archived'])->save();

        adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/versions", periodicTerms())
            ->assertStatus(409)
            ->assertJsonPath('code', 'PACK_ARCHIVED');
    });
});

describe('duplication', function () {
    it('copies a plan under a new code with its latest terms as an unpublished version', function () {
        $source = makePlan(code: 'V1');

        $copy = adminPost($this->token, "/api/v1/admin/packs/plans/{$source->id}/duplicate", ['code' => 'V1-BIS', 'name' => 'Vendeur 1 bis'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.current_version', null)
            ->assertJsonCount(1, 'data.versions')
            ->assertJsonPath('data.versions.0.price_xof', 100000)
            ->assertJsonPath('data.versions.0.published_at', null);

        expect($copy->json('data.versions.0.reason'))->toContain('Copie de V1');

        adminPost($this->token, "/api/v1/admin/packs/plans/{$source->id}/duplicate", ['code' => 'V1-BIS', 'name' => 'Encore'])->assertStatus(409);
    });
});

describe('inherited Vendeur grid', function () {
    it('loads V1 to V12 as drafts with unpublished versions, and only once', function () {
        $this->seed(VendeurDraftCatalogSeeder::class);
        $this->seed(VendeurDraftCatalogSeeder::class);

        expect(PackPlan::query()->count())->toBe(12)
            ->and(PackPlan::query()->where('status', '<>', 'draft')->count())->toBe(0)
            ->and(PackVersion::query()->whereNotNull('published_at')->count())->toBe(0)
            ->and(PackVersion::query()->count())->toBe(12);

        $v6 = PackPlan::query()->where('code', 'V6')->firstOrFail()->versions()->firstOrFail();
        expect($v6->price_xof)->toBe(1500000)
            ->and($v6->quota_per_period)->toBe('2200.000000')
            ->and($v6->period_cycles)->toBe(12);

        $this->withToken(tokenFor(User::factory()->create()))->getJson('/api/v1/packs')->assertOk()->assertJsonCount(0, 'data');
    });
});

describe('Consommateur grid of the former site', function () {
    function consumerTerms(array $rules): array
    {
        return Arr::except(periodicTerms(['rules' => $rules]), ['price_xof', 'quota_per_period']);
    }

    it('derives the price and the monthly quota from the reference value, the discount and the sale rate', function () {
        $plan = createDraftPlan($this->token, 'CONSOMMATEUR', 'C1');

        adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/versions", consumerTerms(['reference_value_xof' => 300000, 'discount_bps' => 1500]))
            ->assertCreated()
            ->assertJsonPath('data.price_xof', 255000)
            ->assertJsonPath('data.quota_per_period', '38.461538')
            ->assertJsonPath('data.rules.rate_setting_key', 'rates.sale_xof_per_yem')
            ->assertJsonPath('data.rules.rate_xof_per_yem', 650);
    });

    it('snapshots the rate: a later change of the setting does not alter the version', function () {
        $plan = createDraftPlan($this->token, 'CONSOMMATEUR', 'C6');
        $id = adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/versions", consumerTerms(['reference_value_xof' => 6000000, 'discount_bps' => 1500]))
            ->assertJsonPath('data.price_xof', 5100000)
            ->json('data.id');

        app(SettingsService::class)->set('rates.sale_xof_per_yem', 700, 'Révision du taux de vente');

        expect(PackVersion::query()->findOrFail($id)->quota_per_period)->toBe('769.230769');
    });

    it('keeps explicit price and quota, and can use another rate', function () {
        $plan = createDraftPlan($this->token, 'CONSOMMATEUR', 'C2');

        adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/versions", [
            ...consumerTerms(['reference_value_xof' => 600000, 'discount_bps' => 1500, 'rate_setting_key' => 'rates.transfer_xof_per_yem']),
            'price_xof' => 500000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.price_xof', 500000)
            ->assertJsonPath('data.quota_per_period', '81.967213')
            ->assertJsonPath('data.rules.rate_xof_per_yem', 610);
    });

    it('refuses an unknown rate setting and a full discount', function (array $rules, string $field) {
        $plan = createDraftPlan($this->token, 'CONSOMMATEUR', 'C3');

        adminPost($this->token, "/api/v1/admin/packs/plans/{$plan->id}/versions", periodicTerms(['rules' => $rules]))
            ->assertStatus(422)
            ->assertJsonStructure(['details' => ['fields' => [$field]]]);
    })->with([
        'unknown rate' => [['reference_value_xof' => 300000, 'rate_setting_key' => 'app.name'], 'rules.rate_setting_key'],
        'full discount' => [['reference_value_xof' => 300000, 'discount_bps' => 10000], 'rules.discount_bps'],
    ]);
});

describe('grid of the former site', function () {
    it('loads Vendeur, Perso, Consommateur and Marchand as drafts, with derived Consommateur terms, and only once', function () {
        $this->seed(LegacyCatalogSeeder::class);
        $this->seed(LegacyCatalogSeeder::class);

        expect(PackPlan::query()->count())->toBe(19)
            ->and(PackPlan::query()->where('status', '<>', 'draft')->count())->toBe(0)
            ->and(PackVersion::query()->whereNotNull('published_at')->count())->toBe(0);

        $version = fn (string $code) => PackPlan::query()->where('code', $code)->firstOrFail()->versions()->firstOrFail();

        expect($version('V2')->quota_per_period)->toBe('333.333333')
            ->and($version('C1')->price_xof)->toBe(255000)
            ->and($version('C1')->quota_per_period)->toBe('38.461538')
            ->and($version('C6')->price_xof)->toBe(5100000)
            ->and($version('PERSO')->rules)->toMatchArray(['discount_bps' => 1500, 'min_topup_xof' => 5000])
            ->and($version('PRESTIGE')->period_cycles)->toBe(48)
            ->and($version('PRESTIGE')->eligible_person_types)->toBe(['legal'])
            ->and($version('BRONZE')->quota_per_period)->toBe('125.000000');
    });
});
