<?php
/**
 * CECT staging rollout: versioned, audited African mobile-money simulator catalogue.
 *
 * Invoke only on the authorized AWS staging host:
 * CECT_AFRICA_SIM_ROLLOUT=YES php scripts/apply-africa-simulation-staging.php
 *
 * Does not change users, quotas, payment records, balances or production data.
 */
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Payment\AfricanMobileMoneyCatalog as Catalog;
use App\Domain\Settings\SettingsService;
use Illuminate\Support\Facades\DB;

if (getenv('CECT_AFRICA_SIM_ROLLOUT') !== 'YES'
    || ! app()->environment('staging')
    || DB::connection()->getDatabaseName() !== 'cectapi_live'
    || ! in_array(config('services.fedapay.environment'), [null, 'sandbox'], true)) {
    throw new RuntimeException('Refusé : opération exclusivement autorisée sur AWS staging en sandbox.');
}

$settings = app(SettingsService::class);
if ($settings->string('payment.inbound_provider') !== 'fake') {
    throw new RuntimeException('Refusé : le simulateur fake doit rester le seul moteur encaissement.');
}

$expectedMethods = Catalog::methods(true);
$expectedModes = Catalog::mobileCollectionModes();
if (count(Catalog::CALLING_CODES) !== 54 || count(Catalog::UEMOA) !== 8 || count($expectedMethods) < 40) {
    throw new RuntimeException('Catalogue africain incomplet.');
}

$beforeUsers = DB::table('users')->count();
$beforePayments = DB::table('payment_attempts')->count();
$beforePacks = DB::table('pack_plans')->count();

DB::transaction(function () use ($settings, $expectedMethods, $expectedModes): void {
    if ($settings->array('payment.methods') !== $expectedMethods) {
        $settings->set(
            'payment.methods',
            $expectedMethods,
            'Extension des réseaux Mobile Money en simulation aux 54 pays africains, BCEAO et GSMA ; aucune passerelle réelle.'
        );
        echo "PAYMENT_METHODS_UPDATED\n";
    }
    if ($settings->array('payment.collection_modes') !== $expectedModes) {
        $settings->set(
            'payment.collection_modes',
            $expectedModes,
            'Activation exclusive des routes de simulation fake pour les nouveaux opérateurs africains.'
        );
        echo "COLLECTION_MODES_UPDATED\n";
    }
});

if (DB::table('users')->count() !== $beforeUsers
    || DB::table('payment_attempts')->count() !== $beforePayments
    || DB::table('pack_plans')->count() !== $beforePacks) {
    throw new RuntimeException('Nombre de comptes, paiements ou modèles de packs modifié : vérifier immédiatement.');
}

echo 'AFRICA_STAGING_SIMULATION_READY countries='.count(Catalog::CALLING_CODES)
    .' methods='.count($settings->array('payment.methods'))
    .' users_unchanged='.$beforeUsers."\n";
