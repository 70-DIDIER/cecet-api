<?php

namespace App\Filament\Widgets;

use App\Domain\Reporting\ReportingQueries;
use Filament\Widgets\Widget;

class PendingDecisions extends Widget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.pending-decisions';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('payments.validate_manual') || $user->can('yem_validations.view'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $pending = app(ReportingQueries::class)->pendingValidations();

        $items = array_values(array_filter([
            $user?->can('payments.validate_manual') ? ['label' => 'Paiements hors prestataire', 'hint' => 'Espèces, dépôts, virements', 'count' => $pending['payments']] : null,
            $user?->can('yem_validations.view') ? ['label' => 'Réceptions de YEM', 'hint' => 'À confirmer ou litiges', 'count' => $pending['yem']] : null,
            $user?->can('yem_validations.view') ? ['label' => 'Paiements marchands', 'hint' => 'Confirmations et litiges', 'count' => $pending['merchant']] : null,
        ]));

        return ['items' => $items, 'total' => array_sum(array_column($items, 'count'))];
    }
}
