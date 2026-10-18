<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Settings\Actions\SyncSettingsCatalog;
use App\Domain\Settings\Exceptions\SettingException;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\Models\SettingVersion;
use App\Domain\Settings\SettingsCatalog;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app(SyncSettingsCatalog::class)->handle();
    $this->settings = app(SettingsService::class);
});

/**
 * Asserts that the callback throws a SettingException with the given machine code.
 */
function expectSettingError(Closure $callback, string $code): void
{
    try {
        $callback();
    } catch (SettingException $exception) {
        expect($exception->errorCode)->toBe($code);

        return;
    }

    test()->fail("Expected SettingException {$code}.");
}

it('initializes every catalog setting with its typed default', function () {
    expect(Setting::query()->count())->toBe(count(app(SettingsCatalog::class)->all()))
        ->and($this->settings->get('membership.fee.natural_person'))->toBe(25000)
        ->and($this->settings->get('membership.fee.legal_person'))->toBe(200000)
        ->and($this->settings->get('packs.families_excluded_from_limit'))->toBe(['PERSONNEL'])
        ->and($this->settings->get('payout.auto_enabled'))->toBeFalse()
        ->and($this->settings->get('yem.cect_pernum'))->toBeNull();
});

it('never overwrites existing values when the catalog is synced again', function () {
    $this->settings->set('rates.sale_xof_per_yem', 700, 'Décision du CA du 08/10');

    $result = app(SyncSettingsCatalog::class)->handle();

    expect($result['initialized'])->toBe([])
        ->and($this->settings->get('rates.sale_xof_per_yem'))->toBe(700);
});

it('creates a new audited version instead of overwriting the value', function () {
    $actor = User::factory()->create();

    $version = $this->settings->set('rates.purchase_xof_per_yem', 520, 'Ratification PO-01 par le CA', actor: $actor);

    expect($this->settings->get('rates.purchase_xof_per_yem'))->toBe(520)
        ->and($this->settings->versions('rates.purchase_xof_per_yem'))->toHaveCount(2)
        ->and($version->created_by)->toBe($actor->id);

    $audit = AuditEvent::query()->where('action', 'settings.version_created')->sole();

    expect($audit)
        ->actor_id->toBe($actor->id)
        ->subject_id->toBe('rates.purchase_xof_per_yem')
        ->reason->toBe('Ratification PO-01 par le CA')
        ->and($audit->before)->toBe(['value' => 510])
        ->and($audit->after['value'])->toBe(520);
});

it('applies a future version only from its effective date and keeps history reproducible', function () {
    $this->freezeTime();
    $before = now();
    $effectiveFrom = now()->addDays(7);

    $this->settings->set('membership.fee.natural_person', 30000, 'Nouvelle grille 2027', $effectiveFrom);

    expect($this->settings->get('membership.fee.natural_person'))->toBe(25000)
        ->and($this->settings->get('membership.fee.natural_person', $effectiveFrom))->toBe(30000);

    $this->travel(8)->days();

    expect($this->settings->get('membership.fee.natural_person'))->toBe(30000)
        ->and($this->settings->get('membership.fee.natural_person', $before))->toBe(25000);
});

it('rejects retroactive changes', function () {
    expectSettingError(
        fn () => $this->settings->set('rates.sale_xof_per_yem', 640, 'Correction rétroactive', now()->subDay()),
        'SETTING_RETROACTIVE_CHANGE',
    );
});

it('validates values against the definition rules', function (string $key, mixed $value) {
    expectSettingError(fn () => $this->settings->set($key, $value, 'Valeur invalide'), 'SETTING_VALUE_INVALID');
})->with([
    'negative fee' => ['membership.fee.natural_person', -1],
    'non integer rate' => ['rates.sale_xof_per_yem', 'abc'],
    'unknown pack family' => ['packs.families_excluded_from_limit', ['INCONNU']],
    'payment method without label' => ['payment.methods', [['code' => 'TMONEY', 'channel' => 'mobile_money', 'enabled' => true]]],
    'unsupported provider' => ['payment.inbound_provider', 'paypal'],
    'otp ttl too short' => ['otp.ttl_seconds', 10],
]);

it('requires a meaningful reason for every change', function () {
    expectSettingError(fn () => $this->settings->set('rates.sale_xof_per_yem', 640, ' '), 'SETTING_VALUE_INVALID');
});

it('rejects unknown settings', function () {
    expectSettingError(fn () => $this->settings->get('does.not.exist'), 'SETTING_NOT_FOUND');
});

it('forbids enabling the demo gateway bypass in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expectSettingError(
        fn () => $this->settings->set('platform.demo_gateway_bypass', true, 'Démo commerciale'),
        'SETTING_VALUE_FORBIDDEN_IN_PRODUCTION',
    );
});

it('ignores a demo bypass value that reaches production', function () {
    $this->settings->set('platform.demo_gateway_bypass', true, 'Démonstration en recette');
    expect($this->settings->get('platform.demo_gateway_bypass'))->toBeTrue();

    app()->detectEnvironment(fn () => 'production');

    expect($this->settings->get('platform.demo_gateway_bypass'))->toBeFalse();
});

it('uses FedaPay as the safe fallback when a fake provider reaches production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect($this->settings->get('payment.inbound_provider'))->toBe('fedapay');
});

it('keeps setting versions immutable at the database level', function () {
    expect(fn () => DB::statement("update setting_versions set reason = 'falsifié'"))->toThrow(QueryException::class);
});

it('serves the new value immediately after a change despite the cache', function () {
    expect($this->settings->get('exchange.reservation_ttl_minutes'))->toBe(1440);

    $this->settings->set('exchange.reservation_ttl_minutes', 60, 'Réservations plus courtes');

    expect($this->settings->get('exchange.reservation_ttl_minutes'))->toBe(60)
        ->and(SettingVersion::query()->where('setting_key', 'exchange.reservation_ttl_minutes')->count())->toBe(2);
});


it('reserves changes of both membership fees to the Superadmin', function () {
    $natural = \App\Domain\Settings\Models\Setting::query()->findOrFail('membership.fee.natural_person');
    $legal = \App\Domain\Settings\Models\Setting::query()->findOrFail('membership.fee.legal_person');
    expect($natural->edit_level->value)->toBe('superadmin')
        ->and($legal->edit_level->value)->toBe('superadmin');
});
