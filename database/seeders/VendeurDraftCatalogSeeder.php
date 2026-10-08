<?php

namespace Database\Seeders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Enums\PackPlanStatus;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Catalog\Models\PackVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the inherited Vendeur grid V1 to V12 (cahier des charges v4.0, annexe A.1) as DRAFT plans with an
 * UNPUBLISHED first version. Nothing is offered to members until the cooperative reviews and publishes it.
 *
 * Opt-in only: php artisan db:seed --class=VendeurDraftCatalogSeeder
 *
 * Only the access price and the monthly quota are taken from the annex. The "counter-value" column is derived
 * from a rate (520 F/YEM) that is not ratified (PO-01) and is deliberately not stored. Carry-over and the
 * end-of-term rule are open decisions (PO-05): the least destructive values are proposed.
 */
class VendeurDraftCatalogSeeder extends Seeder
{
    /**
     * @var array<int, array{price: int, monthly: int}>
     */
    private const GRID = [
        1 => ['price' => 100000, 'monthly' => 125],
        2 => ['price' => 250000, 'monthly' => 250],
        3 => ['price' => 400000, 'monthly' => 500],
        4 => ['price' => 600000, 'monthly' => 750],
        5 => ['price' => 800000, 'monthly' => 1250],
        6 => ['price' => 1500000, 'monthly' => 2200],
        7 => ['price' => 3000000, 'monthly' => 4400],
        8 => ['price' => 7000000, 'monthly' => 8000],
        9 => ['price' => 15000000, 'monthly' => 15000],
        10 => ['price' => 35000000, 'monthly' => 30000],
        11 => ['price' => 50000000, 'monthly' => 40000],
        12 => ['price' => 75000000, 'monthly' => 50000],
    ];

    public function run(AuditLogger $auditLogger): void
    {
        DB::transaction(function () use ($auditLogger): void {
            foreach (self::GRID as $level => $terms) {
                $plan = PackPlan::query()->firstOrCreate(
                    ['code' => "V{$level}"],
                    [
                        'family' => PackFamily::Vendeur,
                        'name' => "Vendeur V{$level}",
                        'level' => $level,
                        'description' => 'Grille héritée du cahier des charges v4.0 : à relire et ratifier avant publication.',
                        'status' => PackPlanStatus::Draft,
                    ],
                );

                if (! $plan->wasRecentlyCreated) {
                    continue;
                }

                PackVersion::query()->create([
                    'pack_plan_id' => $plan->id,
                    'version' => 1,
                    'price_xof' => $terms['price'],
                    'currency' => 'XOF',
                    'period_unit' => 'month',
                    'period_every' => 1,
                    'period_cycles' => 12,
                    'quota_per_period' => number_format($terms['monthly'], 6, '.', ''),
                    'cumulative' => true,
                    'end_of_term' => 'keep',
                    'eligible_person_types' => ['natural', 'legal'],
                    'rules' => [],
                    'reason' => 'Valeurs héritées du cahier des charges v4.0 (annexe A.1) ; cumul et fin de durée à ratifier (PO-05).',
                    'created_at' => now(),
                ]);

                $auditLogger->record('packs.plan_seeded', $plan, after: ['code' => $plan->code, 'price_xof' => $terms['price'], 'monthly_quota' => $terms['monthly']]);
            }
        });
    }
}
