<?php

namespace App\Domain\Backoffice\Menu;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The back-office navigation. Each entry is shown only when its route exists and the user holds one of its
 * permissions (the Superadmin holds them all); hiding is a courtesy, the routes enforce the permissions anyway.
 */
class BackofficeMenu
{
    /**
     * @return list<array{title: string, items: list<array{label: string, icon: string, route: string, active: string}>}>
     */
    public function for(User $user): array
    {
        $sections = [];

        foreach ($this->definition() as $title => $items) {
            $visible = [];

            foreach ($items as $item) {
                if (! Route::has($item['route'])) {
                    continue;
                }

                if ($item['permissions'] !== [] && ! $this->canAny($user, $item['permissions'])) {
                    continue;
                }

                $visible[] = ['label' => $item['label'], 'icon' => $item['icon'], 'route' => $item['route'], 'active' => $item['active'] ?? $item['route']];
            }

            if ($visible !== []) {
                $sections[] = ['title' => $title, 'items' => $visible];
            }
        }

        return $sections;
    }

    /**
     * @return array<string, list<array{label: string, icon: string, route: string, permissions: list<string>, active?: string}>>
     */
    private function definition(): array
    {
        return [
            'Pilotage' => [
                ['label' => 'Tableau de bord', 'icon' => 'bi-grid-fill', 'route' => 'backoffice.dashboard', 'permissions' => []],
                ['label' => 'Validations', 'icon' => 'bi-check2-square', 'route' => 'backoffice.validations', 'permissions' => ['payments.validate_manual', 'yem_validations.view'], 'active' => 'backoffice.validations'],
            ],
            'Finance' => [
                ['label' => 'Versements', 'icon' => 'bi-cash-coin', 'route' => 'backoffice.finance.payouts', 'permissions' => ['payouts.view'], 'active' => 'backoffice.finance'],
            ],
            'Adhérents' => [
                ['label' => 'Membres', 'icon' => 'bi-people-fill', 'route' => 'backoffice.members', 'permissions' => ['members.view'], 'active' => 'backoffice.members'],
            ],
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function canAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
