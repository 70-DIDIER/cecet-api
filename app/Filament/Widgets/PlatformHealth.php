<?php

namespace App\Filament\Widgets;

use App\Domain\Ops\OpsMonitor;
use Filament\Widgets\Widget;

class PlatformHealth extends Widget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.platform-health';

    public static function canView(): bool
    {
        return auth()->user()?->can('security_events.view') === true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['checks' => app(OpsMonitor::class)->check()];
    }
}
