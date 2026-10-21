<?php

namespace App\Domain\Access\Console;

use App\Domain\Access\PermissionCatalog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A read-only pre-presentation inspection. Does not seed, activate, reset, publish
 * or modify any member, pack, rate or security material.
 */
final class DemoReadinessCommand extends Command
{
    protected $signature = 'cect:demo-check {--json : Output a machine-readable readiness report}';

    protected $description = 'Read-only checks for pack coverage, protected SuperAdmin and staff demo readiness';

    public function handle(): int
    {
        $published = DB::table('pack_plans')
            ->where('status', 'published')
            ->selectRaw('family, COUNT(*) AS total')
            ->groupBy('family')
            ->pluck('total', 'family');

        $families = ['VENDEUR', 'CONSOMMATEUR', 'PERSONNEL', 'MARCHAND'];
        $catalog = [];
        $issues = [];

        foreach ($families as $family) {
            $catalog[$family] = (int) ($published[$family] ?? 0);
            if ($catalog[$family] === 0) {
                $issues[] = "Aucun pack {$family} publié ; confirmer tarifs, quotas et niveaux avant publication.";
            }
        }

        $superadmins = User::query()->role(PermissionCatalog::SUPERADMIN)->count();
        if ($superadmins !== 1 || ! (bool) config('superadmin.enabled')) {
            $issues[] = 'L’unicité et la protection mTLS SuperAdmin doivent être vérifiées.';
        }

        $profiles = DB::table('superadmin_security_profiles')->count();
        $activeKeys = DB::table('superadmin_access_keys')->where('status', 'active')->count();
        if ($profiles !== 1 || $activeKeys !== 1) {
            $issues[] = 'Profil de sécurité SuperAdmin ou clé cryptographique active absente/en double.';
        }

        $guardian = (bool) config('staff_guardian.enabled');
        if (! $guardian) {
            $issues[] = 'Connexion du personnel par OTP envoyé aux trois e-mails non activée.';
        }

        $staff = [];
        foreach ([PermissionCatalog::ADMIN, PermissionCatalog::ACCOUNTING] as $role) {
            $staff[$role] = User::query()->role($role)->where('status', 'active')->count();
            if ($staff[$role] === 0) {
                $issues[] = "Aucun compte {$role} actif pour jouer son rôle pendant la présentation.";
            }
        }

        $report = [
            'ready' => count($issues) === 0,
            'environment' => app()->environment(),
            'database' => DB::connection()->getDatabaseName(),
            'published_packs_by_family' => $catalog,
            'member_count' => User::query()->where('kind', 'member')->count(),
            'superadmins' => $superadmins,
            'superadmin_profiles' => $profiles,
            'active_superadmin_keys' => $activeKeys,
            'staff_guardian_enabled' => $guardian,
            'active_staff_by_role' => $staff,
            'issues' => $issues,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('CECT : contrôle de préparation à la démonstration (lecture seule)');
            $this->line('Packs publiés : '.json_encode($catalog, JSON_UNESCAPED_UNICODE));
            $this->line('Membres : '.$report['member_count'].' ; SuperAdmin : '.$superadmins.' ; clés actives : '.$activeKeys);
            foreach ($issues as $issue) {
                $this->warn('À TRAITER : '.$issue);
            }
            $this->line($report['ready'] ? 'PRÊT pour les scénarios de présentation.' : 'NON PRÊT : décisions/configurations requises.');
        }

        return $report['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
