<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Actions\ConfirmStepUp;
use App\Domain\Reporting\Jobs\GenerateSnapshotJob;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsService;
use App\Filament\Pages\Exports;
use App\Filament\Resources\Settings\Pages\ListSettings;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    $this->superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    $this->validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
    $this->fee = Setting::query()->findOrFail('membership.fee.natural_person');
    $this->critical = Setting::query()->findOrFail('auth.password_min_length');
});

function stepUpOpen(): array
{
    return [ConfirmStepUp::SESSION_KEY => now()->addMinutes(10)->getTimestamp()];
}

describe('configuration', function () {
    it('lists the settings with their current value', function () {
        $this->actingAs($this->admin, 'web');

        Livewire::test(ListSettings::class)->assertCanSeeTableRecords([$this->fee, $this->critical])->assertSee('membership.fee.natural_person');
    });

    it('is closed to accounts without the permission', function () {
        $this->actingAs($this->validator, 'web')->get('/admin/configuration')->assertForbidden();
    });

    it('creates a new version of a setting once the identity is confirmed', function () {
        $this->actingAs($this->superadmin, 'web')->withSession(stepUpOpen());

        Livewire::test(ListSettings::class)->callTableAction('edit', $this->fee, ['value' => 30000, 'reason' => 'Nouvelle grille 2027']);

        expect(app(SettingsService::class)->integer('membership.fee.natural_person'))->toBe(30000);
        expect(AuditEvent::query()->where('action', 'settings.version_created')->exists())->toBeTrue();
    });

    it('does not change anything without the confirmation code', function () {
        $this->actingAs($this->superadmin, 'web');

        Livewire::test(ListSettings::class)->callTableAction('edit', $this->fee, ['value' => 30000, 'reason' => 'Nouvelle grille 2027', 'step_up_code' => '000000']);

        expect(app(SettingsService::class)->integer('membership.fee.natural_person'))->toBe(25000);
    });

    it('refuses a value that breaks the rules of the setting', function () {
        $this->actingAs($this->superadmin, 'web')->withSession(stepUpOpen());

        Livewire::test(ListSettings::class)->callTableAction('edit', $this->fee, ['value' => -5, 'reason' => 'Valeur absurde']);

        expect(app(SettingsService::class)->integer('membership.fee.natural_person'))->toBe(25000);
    });

    it('keeps the critical settings to the superadmin', function () {
        $this->actingAs($this->admin, 'web');
        Livewire::test(ListSettings::class)->assertTableActionHidden('edit', $this->critical)->assertTableActionHidden('edit', $this->fee);

        $this->actingAs($this->superadmin, 'web');
        Livewire::test(ListSettings::class)->assertTableActionVisible('edit', $this->critical)->assertTableActionVisible('edit', $this->fee);
    });
});

describe('exports', function () {
    it('shows the exports to those who may read the reports', function () {
        $this->actingAs($this->admin, 'web')->get('/admin/exports')->assertOk()->assertSee('Liste des membres')->assertSee('Snapshots consolidés');
        $this->actingAs($this->validator, 'web')->get('/admin/exports')->assertOk();
    });

    it('downloads a CSV once the identity is confirmed and audits it', function () {
        $this->actingAs($this->admin, 'web')->withSession(stepUpOpen());

        Livewire::test(Exports::class)->callAction('exportMembers')->assertFileDownloaded();

        expect(AuditEvent::query()->where('action', 'reports.exported')->exists())->toBeTrue();
    });

    it('exports nothing without the confirmation code', function () {
        $this->actingAs($this->admin, 'web');

        Livewire::test(Exports::class)->callAction('exportMembers', ['step_up_code' => '000000'])->assertNoFileDownloaded();

        expect(AuditEvent::query()->where('action', 'reports.exported')->exists())->toBeFalse();
    });

    it('queues the generation of a snapshot', function () {
        Queue::fake();
        $this->actingAs($this->admin, 'web');

        Livewire::test(Exports::class)->callAction(TestAction::make('generate')->table());

        Queue::assertPushed(GenerateSnapshotJob::class);
    });
});
