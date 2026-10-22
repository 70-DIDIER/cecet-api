<?php

namespace Database\Seeders;

use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Enums\PackPlanStatus;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Catalog\Models\PackVersion;
use App\Domain\Catalog\PackVersionValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Business decision 2026-10-22: Personnel is a prepaid top-up wallet,
 * with no period, periodic quota, time-gated credits or monthly usage cap.
 * The zero-cost account opening is distinct from actual YEM top-ups: each
 * amount chosen is charged at the live sale rate and credited after settlement.
 * This opt-in demo seed never overwrites a customer subscription or a plan.
 */
class PersonnelOnDemandSeeder extends Seeder
{
    public function run(PackVersionValidator $validator): void
    {
        DB::transaction(static function () use ($validator): void {
            if (PackPlan::query()->where('code', 'PRS-LIBRE')->exists()) {
                return;
            }
            $plan = PackPlan::query()->create([
                'code' => 'PRS-LIBRE',
                'family' => PackFamily::Personnel,
                'name' => 'Personnel Libre',
                'level' => 1,
                'description' => 'Achetez des YEM au moment souhaité. Aucun calendrier, aucune échéance ni restriction de consommation par période. Les YEM acquis sont utilisables dès confirmation de chaque paiement.',
                'status' => PackPlanStatus::Published,
                'published_at' => now(),
            ]);
            $terms = $validator->validate(PackFamily::Personnel, [
                'price_xof' => 0,
                'eligible_person_types' => ['natural'],
                'rules' => [
                    'min_topup_xof' => 650,
                    'rate_setting_key' => 'rates.sale_xof_per_yem',
                    'cashback' => ['enabled' => false, 'rate_bps' => 0, 'max_yem' => null, 'rounding' => 'down'],
                ],
                'reason' => 'Décision métier CECT : Personnel à la demande, recharge libre, sans aucune période.',
            ]);
            PackVersion::query()->create([
                ...$terms,
                'pack_plan_id' => $plan->id,
                'version' => 1,
                'currency' => 'XOF',
                'published_at' => now(),
                'created_at' => now(),
            ]);
        });
    }
}
