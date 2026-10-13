<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    $this->validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
    $this->accounting = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();

    // A member pays the membership in cash (validated), then a Vendeur pack and a Consommateur pack through the provider.
    $this->member = User::factory()->status(UserStatus::PendingPayment)->create(['first_name' => 'Yendoukoa', 'last_name' => 'NAKORE']);
    $this->member->pernumIdentities()->create(['pernum' => '1001043953', 'kind' => 'personal', 'status' => 'declared']);

    $attemptId = $this->withToken(tokenFor($this->member))
        ->postJson('/api/v1/me/membership/payments', ['method' => 'CASH'], ['Idempotency-Key' => (string) Str::ulid()])
        ->json('data.id');
    $this->withToken(tokenFor($this->accounting, withStepUp: true))
        ->postJson("/api/v1/validations/payments/{$attemptId}/approve", ['received_amount' => 25000, 'reference' => 'Reçu 1'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    subscribeAndPay($this->member->refresh(), makePlan(PackFamily::Vendeur, 'V1'));
    subscribeAndPay($this->member, makePlan(PackFamily::Consommateur, 'C1', [
        'period' => ['unit' => 'month', 'every' => 1, 'cycles' => 12],
        'cumulative' => true,
        'end_of_term' => 'expire',
        'rules' => ['reference_value_xof' => 300000],
        'reason' => 'Grille de l\'ancien site',
    ]));

    // A second member who has not paid yet.
    $this->unpaid = User::factory()->status(UserStatus::PendingPayment)->create();
    $this->withToken(tokenFor($this->unpaid))->postJson('/api/v1/me/membership/payments', ['method' => 'CASH'], ['Idempotency-Key' => (string) Str::ulid()]);
});

it('summarises the period: collected amounts net of fees, sources, methods and top members', function () {
    $response = $this->withToken(tokenFor($this->admin))->getJson('/api/v1/admin/reports/summary')->assertOk();

    expect($response->json('data.kpis'))->toMatchArray([
        'total_collected_xof' => 425000,
        'confirmed_payments' => 3,
        'average_payment_xof' => 141666,
        'new_members' => 1,
        'manual_validations' => 1,
        'manual_rejections' => 0,
        'pending_payments' => 1,
        'pending_amount_xof' => 25000,
        'yem_in_circulation' => '163.461538',
    ]);

    expect(collect($response->json('data.by_source'))->pluck('amount_xof', 'key')->all())
        ->toBe(['pack_consommateur' => 300000, 'pack_vendeur' => 100000, 'membership' => 25000]);
    expect(collect($response->json('data.by_method'))->firstWhere('method', 'CASH')['amount_xof'])->toBe(25000);

    $response->assertJsonPath('data.top_members.0.pernum', '1001043953')
        ->assertJsonPath('data.top_members.0.amount_xof', 425000)
        ->assertJsonPath('data.top_members.0.share_bps', 10000);
});

it('filters the summary by period', function () {
    $this->withToken(tokenFor($this->admin))->getJson('/api/v1/admin/reports/summary?from=2020-01-01&to=2020-01-31')
        ->assertOk()
        ->assertJsonPath('data.kpis.total_collected_xof', 0)
        ->assertJsonPath('data.by_source', []);

    $this->withToken(tokenFor($this->admin))->getJson('/api/v1/admin/reports/summary?from=2026-02-01&to=2026-01-01')->assertStatus(422);
});

it('gives the back-office home figures', function () {
    $this->withToken(tokenFor($this->admin))->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertJsonPath('data.members', 2)
        ->assertJsonPath('data.total_collected_xof', 425000)
        ->assertJsonPath('data.pending_validations', 1)
        ->assertJsonPath('data.yem_in_circulation', '163.461538');
});

it('lists members with Pernum, membership, active packs and YEM balance, searchable by Pernum', function () {
    $this->withToken(tokenFor($this->admin))->getJson('/api/v1/admin/members?search=1001043')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.pernum', '1001043953')
        ->assertJsonPath('data.0.membership_status', 'active')
        ->assertJsonPath('data.0.active_families', ['CONSOMMATEUR', 'VENDEUR'])
        ->assertJsonPath('data.0.yem_balance', '163.461538');

    $this->withToken(tokenFor($this->admin))->getJson('/api/v1/admin/members?family=MARCHAND')->assertJsonCount(0, 'data');
    $this->withToken(tokenFor($this->admin))->getJson('/api/v1/admin/members?family=VENDEUR')->assertJsonCount(1, 'data');
});

it('exports members, payments and subscriptions as CSV, audited and with a step-up', function () {
    $this->withToken(tokenFor($this->admin))->get('/api/v1/admin/exports/members')->assertForbidden();

    $members = $this->withToken(tokenFor($this->admin, withStepUp: true))->get('/api/v1/admin/exports/members')->assertOk();
    $csv = $members->streamedContent();

    expect($members->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv)->toStartWith("\u{FEFF}\"Numéro membre\";Nom;Pernum")
        ->and($csv)->toContain('"Yendoukoa NAKORE";1001043953');

    $payments = $this->withToken(tokenFor($this->admin, withStepUp: true))->get('/api/v1/admin/exports/payments')->streamedContent();
    expect(substr_count(trim($payments), "\n"))->toBe(3)
        ->and($payments)->toContain('Adhésions')->toContain('CASH');

    $subscriptions = $this->withToken(tokenFor($this->admin, withStepUp: true))->get('/api/v1/admin/exports/subscriptions')->streamedContent();
    expect($subscriptions)->toContain(';C1;300000;');

    $audit = $this->withToken(tokenFor($this->admin, withStepUp: true))->get('/api/v1/admin/exports/audit')->assertOk()->streamedContent();
    expect($audit)->toContain('reports.exported');

    $this->withToken(tokenFor($this->admin, withStepUp: true))->get('/api/v1/admin/exports/exchanges')->assertOk();
    $this->withToken(tokenFor($this->admin, withStepUp: true))->get('/api/v1/admin/exports/payouts')->assertOk();

    expect(AuditEvent::query()->where('action', 'reports.exported')->count())->toBe(6);
    $this->withToken(tokenFor($this->admin, withStepUp: true))->get('/api/v1/admin/exports/secrets')->assertNotFound();
});

it('neutralises spreadsheet formulas typed by members', function () {
    $this->unpaid->forceFill(['city' => '=HYPERLINK("http://evil")'])->save();

    $csv = $this->withToken(tokenFor($this->admin, withStepUp: true))->get('/api/v1/admin/exports/members')->streamedContent();

    expect($csv)->toContain("'=HYPERLINK");
});

it('reserves reports to staff allowed to see them', function () {
    $this->withToken(tokenFor($this->validator))->getJson('/api/v1/admin/reports/summary')->assertForbidden();
    $this->withToken(tokenFor($this->member))->getJson('/api/v1/admin/overview')->assertForbidden();
});

it('gives the standard monthly report and a printable version with the same filters', function () {
    $month = now()->format('Y-m');

    $this->withToken(tokenFor($this->admin))->getJson("/api/v1/admin/reports/summary?month={$month}")
        ->assertOk()
        ->assertJsonPath('data.period.from', now()->startOfMonth()->toIso8601ZuluString())
        ->assertJsonPath('data.kpis.total_collected_xof', 425000);

    $this->withToken(tokenFor($this->admin))->getJson('/api/v1/admin/reports/summary?month=2026-09&from=2026-09-01')->assertStatus(422);

    $html = $this->withToken(tokenFor($this->admin))->get("/api/v1/admin/reports/summary/print?month={$month}")
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'")
        ->assertHeader('X-Frame-Options', 'DENY')
        ->getContent();

    expect($html)->toContain('Rapport d\'activité de la CECT')
        ->toContain('425 000 FCFA')
        ->toContain('Yendoukoa NAKORE')
        ->toContain('Reste dû à ce jour');

    $pdf = $this->withToken(tokenFor($this->admin, withStepUp: true))->get("/api/v1/admin/reports/summary.pdf?month={$month}")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF-1.4');

    $xlsx = $this->withToken(tokenFor($this->admin, withStepUp: true))->get("/api/v1/admin/reports/summary.xlsx?month={$month}")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $xlsx->assertDownload();
});
