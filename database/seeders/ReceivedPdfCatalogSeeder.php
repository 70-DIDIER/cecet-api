<?php

namespace Database\Seeders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Enums\PackPlanStatus;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Catalog\Models\PackVersion;
use App\Domain\Catalog\PackVersionValidator;
use App\Support\YemQuantity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Classeur1.pdf received 22 October 2026 (2 pages).
 *
 * Opt-in only; never changes any already-existing catalog code/contract.
 * 12 Vendeur, 8 Consommateur, 9 fully-priced Marchand published.
 * One Marchand price is "###" in the source: retained as a draft without a sellable version.
 *
 * CECT confirmed: FULL upfront payment then periodic YEM credits; 8% bonus in YEM
 * (not the obsolete fiat discount displayed in the submitted sheet).
 */
class ReceivedPdfCatalogSeeder extends Seeder
{
    /** @var list<array{string,int,int}> */
    private const SELLER = [
        ['Éclaireur', 100000, 40],
        ['Pionnier', 250000, 100],
        ['Bâtisseur', 350000, 140],
        ['Stratège', 600000, 240],
        ['Conquérant', 800000, 320],
        ['Ambassadeur', 1500000, 600],
        ['Commandeur', 3000000, 1200],
        ['Visionnaire', 7000000, 2800],
        ['Magnat', 15000000, 6000],
        ['Titan', 35000000, 14000],
        ['Grand Maître', 50000000, 20000],
        ['Légende', 75000000, 30000],
    ];

    /** @var list<array{string,int,int}> */
    private const MERCHANT = [
        ['Apprenti Marchand', 240000, 6000],
        ['Compagnon du Négoce', 600000, 15000],
        ['Artisan du Négoce', 1200000, 30000],
        ['Négociant', 2600000, 65000],
        ['Grand Négociant', 5600000, 140000],
        ['Maître Marchand', 0, 375000], // Price unreadable ("###"); DRAFT.
        ['Doyen du Marché', 30000000, 750000],
        ['Notable du Négoce', 60000000, 1500000],
        ['Grand Seigneur du Commerce', 80000000, 2000000],
        ['Souverain du Marché', 120000000, 3000000],
    ];

    /** @var list<array{string,int}> */
    private const CONSUMER = [
        ['Client Opale', 300000],
        ['Client Turquoise', 500000],
        ['Client Grenat', 800000],
        ['Client Topaze', 1000000],
        ['Client Émeraude', 1500000],
        ['Client Saphir', 2000000],
        ['Client Rubis', 3000000],
        ['Client Diamant', 6000000],
    ];

    public function run(PackVersionValidator $validator, AuditLogger $audit): void
    {
        DB::transaction(function () use ($validator, $audit): void {
            foreach (self::SELLER as $i => [$name, $price, $biweekly]) {
                $this->savePack($validator, $audit, PackFamily::Vendeur, sprintf('ASC-%02d', $i+1), $name, $i+1, $price, (string) $biweekly,
                    'Pack Ascension · Vendeur PF. Capacité de cession de YEM par quinzaine, 24 distributions. Le premier quota suit le délai validé par la formule.',
                    ['first_credit_delay_periods' => 1], true);
            }

            foreach (self::MERCHANT as $i => [$name, $price, $total]) {
                // The spreadsheet lists 120% reserve/liberation and 3% merchant fees.
                // Those are not quotas and must not be smuggled into the YEM allowance ledger.
                $perCycle = bcdiv((string) $total, '24', YemQuantity::SCALE);
                $this->savePack($validator, $audit, PackFamily::Marchand, sprintf('CPT-%02d', $i+1), $name, $i+1, $price, $perCycle,
                    'Pack Comptoir · Marchand PM. Plafond quinzaine dérivé de la capacité contractuelle de YEM. Réserve à 120% et commission de gestion 3% : conditions de transaction distinctes du quota.',
                    ['first_credit_delay_periods' => 0], $price > 0);
            }

            foreach (self::CONSUMER as $i => [$name, $price]) {
                // Use exact 650 XOF/YEM snapshot, never rounded "quantity per quinzaine" from the spreadsheet.
                // Frozen underlying settings have been validated at 650 XOF/YEM on 22 October 2026.
                $perCycle = bcdiv((string) $price, (string) (650 * 24), YemQuantity::SCALE);
                $this->savePack($validator, $audit, PackFamily::Consommateur, sprintf('CLT-%02d', $i+1), $name, $i+1, $price, $perCycle,
                    'Pack Consommateur PF. Paiement intégral, YEM distribués sur 24 quinzaines. La remise monétaire de 8% de l’ancien tableau est remplacée, selon décision CECT, par un bonus YEM de 8%.',
                    [
                        'first_credit_delay_periods' => 0,
                        'rate_setting_key' => 'rates.sale_xof_per_yem',
                        'rate_xof_per_yem' => 650,
                        'cashback' => ['enabled' => true, 'rate_bps' => 800, 'max_yem' => null, 'rounding' => 'down'],
                    ], true);
            }
        });
    }

    private function savePack(
        PackVersionValidator $validator, AuditLogger $audit, PackFamily $family,
        string $code, string $name, int $level, int $price, string $quota,
        string $description, array $rules, bool $publish
    ): void {
        $existing = PackPlan::query()->where('code', $code)->first();
        if ($existing !== null) {
            if ($existing->family !== $family) {
                throw new RuntimeException("Conflicting catalog family for existing pack {$code}");
            }
            return;
        }

        $isDraft = ! $publish;
        $plan = PackPlan::query()->create([
            'family' => $family,
            'code' => $code,
            'name' => $name,
            'level' => $level,
            'description' => $description,
            'status' => $isDraft ? PackPlanStatus::Draft : PackPlanStatus::Published,
            'published_at' => $isDraft ? null : now(),
        ]);

        if ($isDraft) {
            // No guessed price, no fictitious public offering.
            $audit->record('packs.plan_seeded', $plan, after: ['code' => $code, 'reason' => 'Prix non lisible dans le document reçu : publication bloquée.']);
            return;
        }

        $terms = $validator->validate($family, [
            'price_xof' => $price,
            'period' => ['unit' => 'half_month', 'every' => 1, 'cycles' => 24],
            'quota_per_period' => $quota,
            'cumulative' => true,
            'end_of_term' => 'keep',
            'eligible_person_types' => $family->defaultEligiblePersonTypes(),
            'rules' => $rules,
            'reason' => 'Import document Classeur1.pdf reçu le 22/10/2026 ; règle de paiement et de cashback confirmée CECT.',
        ]);
        $version = PackVersion::query()->create([
            ...$terms,
            'pack_plan_id' => $plan->id,
            'version' => 1,
            'currency' => 'XOF',
            'published_at' => now(),
            'created_at' => now(),
        ]);
        $audit->record('packs.plan_seeded', $plan, after: [
            'source' => 'Classeur1.pdf 2026-10-22',
            'code' => $code,
            'family' => $family->value,
            'name' => $name,
            'price_xof' => $price,
            'quota_per_half_month' => $version->quota_per_period,
            'version' => $version->id,
            'rules' => $rules,
        ]);
    }
}
