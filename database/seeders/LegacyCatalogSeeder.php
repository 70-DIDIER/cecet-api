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

/**
 * Loads the pack grid of the former site (scoopcectogo.com) as DRAFT plans with an UNPUBLISHED first version:
 * Vendeur V1-V6, Perso, Consommateur C1-C6 and Marchand Bronze-Prestige. Nothing is offered to members until the
 * cooperative reviews and publishes each plan from the back-office.
 *
 * Opt-in only: php artisan db:seed --class=LegacyCatalogSeeder (alternative to VendeurDraftCatalogSeeder, which
 * follows the annex of the specification; a code already present is left untouched).
 *
 * Values to confirm before publication: the Vendeur quotas are the annual quotas of the former site spread over
 * 12 months (V6: 26 000 / 12 = 2 166.67 a month, where the former site displayed 2 250), the minimum Perso top-up
 * (5 000 F, the smallest top-up seen) and, for every family, carry-over and the end-of-term rule (PO-05).
 */
class LegacyCatalogSeeder extends Seeder
{
    private const REASON = 'Grille de l\'ancien site (scoopcectogo.com), à relire et ratifier avant publication.';

    /**
     * @var array<string, array{price: int, annual: int}>
     */
    private const VENDEUR = [
        'V1' => ['price' => 100000, 'annual' => 1500],
        'V2' => ['price' => 250000, 'annual' => 4000],
        'V3' => ['price' => 400000, 'annual' => 6000],
        'V4' => ['price' => 600000, 'annual' => 9000],
        'V5' => ['price' => 800000, 'annual' => 15000],
        'V6' => ['price' => 1500000, 'annual' => 26000],
    ];

    /**
     * Reference values; the member pays them in full and receives them in YEM at the sale rate, plus the cashback if the Superadmin enabled one.
     *
     * @var array<string, int>
     */
    private const CONSOMMATEUR = [
        'C1' => 300000,
        'C2' => 600000,
        'C3' => 1200000,
        'C4' => 1800000,
        'C5' => 3000000,
        'C6' => 6000000,
    ];

    /**
     * @var array<string, array{name: string, price: int, total: int}>
     */
    private const MARCHAND = [
        'BRONZE' => ['name' => 'Bronze', 'price' => 100000, 'total' => 6000],
        'ARGENT' => ['name' => 'Argent', 'price' => 300000, 'total' => 15000],
        'OR' => ['name' => 'Or', 'price' => 500000, 'total' => 30000],
        'DIAMANT' => ['name' => 'Diamant', 'price' => 1000000, 'total' => 65000],
        'PLATINE' => ['name' => 'Platine', 'price' => 2000000, 'total' => 140000],
        'PRESTIGE' => ['name' => 'Prestige', 'price' => 5000000, 'total' => 350000],
    ];

    private const MERCHANT_MONTHS = 48;

    public function run(AuditLogger $auditLogger, PackVersionValidator $validator): void
    {
        DB::transaction(function () use ($auditLogger, $validator): void {
            $level = 0;

            foreach (self::VENDEUR as $code => $terms) {
                $this->seedPlan($auditLogger, $validator, PackFamily::Vendeur, $code, "Vendeur {$code}", ++$level, [
                    'price_xof' => $terms['price'],
                    'period' => ['unit' => 'month', 'every' => 1, 'cycles' => 12],
                    'quota_per_period' => bcdiv((string) $terms['annual'], '12', 6),
                ]);
            }

            $this->seedPlan($auditLogger, $validator, PackFamily::Personnel, 'PERSO', 'Perso', 0, [
                'price_xof' => 0,
                'rules' => ['min_topup_xof' => 5000, 'rate_setting_key' => 'rates.sale_xof_per_yem'],
            ]);

            $level = 0;

            foreach (self::CONSOMMATEUR as $code => $reference) {
                $this->seedPlan($auditLogger, $validator, PackFamily::Consommateur, $code, "Consommateur {$code}", ++$level, [
                    'period' => ['unit' => 'month', 'every' => 1, 'cycles' => 12],
                    'rules' => ['reference_value_xof' => $reference],
                ]);
            }

            $level = 0;

            foreach (self::MARCHAND as $code => $terms) {
                $this->seedPlan($auditLogger, $validator, PackFamily::Marchand, $code, "Marchand {$terms['name']}", ++$level, [
                    'price_xof' => $terms['price'],
                    'period' => ['unit' => 'month', 'every' => 1, 'cycles' => self::MERCHANT_MONTHS],
                    'quota_per_period' => bcdiv((string) $terms['total'], (string) self::MERCHANT_MONTHS, 6),
                ]);
            }
        });
    }

    public static function describe(PackFamily $family): string
    {
        return match ($family) {
            PackFamily::Consommateur => 'Un quota de YEM crédité chaque mois pour payer chez les marchands du réseau.',
            PackFamily::Personnel => 'Un solde que vous rechargez à la demande, avec une remise sur chaque rechargement.',
            PackFamily::Vendeur => 'Vendez vos YEM à la CECT et recevez le montant en FCFA.',
            PackFamily::Marchand => 'Encaissez des YEM auprès de vos clients et faites-les racheter par la CECT.',
        };
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    private function seedPlan(AuditLogger $auditLogger, PackVersionValidator $validator, PackFamily $family, string $code, string $name, int $level, array $terms): void
    {
        $plan = PackPlan::query()->firstOrCreate(
            ['code' => $code],
            [
                'family' => $family,
                'name' => $name,
                'level' => $level === 0 ? null : $level,
                'description' => self::describe($family),
                'status' => PackPlanStatus::Draft,
            ],
        );

        if (! $plan->wasRecentlyCreated) {
            return;
        }

        $periodic = $family->isPeriodic();
        $attributes = $validator->validate($family, [
            ...$terms,
            ...($periodic ? ['cumulative' => true, 'end_of_term' => 'keep'] : []),
            'reason' => self::REASON.' Cumul et fin de durée à ratifier (PO-05).',
        ]);

        PackVersion::query()->create([
            ...$attributes,
            'pack_plan_id' => $plan->id,
            'version' => 1,
            'currency' => 'XOF',
            'created_at' => now(),
        ]);

        $auditLogger->record('packs.plan_seeded', $plan, after: [
            'code' => $code,
            'price_xof' => $attributes['price_xof'],
            'quota_per_period' => $attributes['quota_per_period'] ?? null,
        ]);
    }
}
