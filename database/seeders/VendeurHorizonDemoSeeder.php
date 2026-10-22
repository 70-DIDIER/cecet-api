<?php

namespace Database\Seeders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Enums\PackPlanStatus;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Catalog\Models\PackVersion;
use App\Domain\Catalog\PackVersionValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Test-only PF Seller offer. Explicitly opt-in, only on an isolated testing/staging environment.
 * 100,000 XOF is a SIMULATED upfront price, never a charge to a real payment gateway.
 * Each active cycle authorises cession of 10k YEM owned by the seller; it does NOT issue YEM.
 */
class VendeurHorizonDemoSeeder extends Seeder
{
    public const CODE = 'TST-VND-HORIZON';

    public function run(PackVersionValidator $validator, AuditLogger $audit): void
    {
        if (! app()->environment(['testing', 'staging'])) {
            throw new RuntimeException('Le pack Vendeur de test ne peut être publié que dans un environnement de simulation.');
        }

        DB::transaction(function () use ($validator, $audit): void {
            $existing = PackPlan::query()->where('code', self::CODE)->first();
            if ($existing !== null) {
                if ($existing->family !== PackFamily::Vendeur || $existing->name !== 'Vendeur Horizon — TEST') {
                    throw new RuntimeException('Conflit avec un code de pack préexistant : import annulé.');
                }
                return; // Idempotent. Contract/versions/subscriptions must not be overwritten.
            }

            $terms = $validator->validate(PackFamily::Vendeur, [
                'price_xof' => 100000,
                'period' => ['unit' => 'half_month', 'every' => 1, 'cycles' => 24],
                'quota_per_period' => '10000',
                'min_operation' => '1',
                'max_operation' => '10000',
                'cumulative' => true,
                'end_of_term' => 'keep',
                'eligible_person_types' => ['natural'],
                'rules' => [
                    'first_credit_delay_periods' => 0,
                    'rate_setting_key' => 'rates.purchase_xof_per_yem',
                ],
                'reason' => 'Chaîne de tests CECT en staging : souscription, quota vendeur, cession, réservation, validation et versement fictif.',
            ]);

            $plan = PackPlan::query()->create([
                'family' => PackFamily::Vendeur,
                'code' => self::CODE,
                'name' => 'Vendeur Horizon — TEST',
                'level' => 99,
                'description' => 'OFFRE DE DÉMONSTRATION — AUCUNE VALEUR RÉELLE. Pack Vendeur pour personne physique : souscription de 100 000 FCFA simulés, capacité de vente de 10 000 YEM de votre propre portefeuille à chaque quinzaine, 24 périodes (240 000 YEM de droits). Premier droit disponible après confirmation du paiement simulé. Sert à tester réservations, transferts déclarés, validation comptable et versements fictifs. Ne représente ni des YEM offerts ni une promesse de paiement réel.',
                'status' => PackPlanStatus::Published,
                'published_at' => now(),
            ]);

            $version = PackVersion::query()->create([
                ...$terms,
                'pack_plan_id' => $plan->id,
                'version' => 1,
                'currency' => 'XOF',
                'published_at' => now(),
                'created_at' => now(),
            ]);
            $audit->record('packs.demo_created', $plan, after: [
                'code' => self::CODE, 'version_id' => $version->id,
                'price_xof_simulated' => 100000, 'quota_per_half_month' => '10000.000000',
                'cycles' => 24, 'contractual_rights_yem' => '240000.000000',
                'first_credit_delay_periods' => 0, 'staging_only' => true,
            ], reason: 'Pack d’essai demandé pour simuler la chaîne Vendeur PF ; aucune modification des packs officiels.');
        });
    }
}
