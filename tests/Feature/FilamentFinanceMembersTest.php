<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Exchange\Actions\SellerExchangeService;
use App\Domain\Exchange\Actions\ValidationDesk;
use App\Domain\Identity\Actions\ConfirmStepUp;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Payout\Enums\ManualSettlementStatus;
use App\Domain\Payout\Models\ManualSettlement;
use App\Domain\Payout\Models\PayoutBatch;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Filament\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Resources\Payouts\Pages\ViewPayout;
use App\Filament\Resources\Settlements\Pages\ListSettlements;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    configureCectPernum();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    $this->validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
    $this->accounting = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();
    $this->checker = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();
});

function stepUpSession(): array
{
    return [ConfirmStepUp::SESSION_KEY => now()->addMinutes(10)->getTimestamp()];
}

function payoutFor(string $quantity, string $method = 'TMONEY'): PayoutBatch
{
    [$member, $pack] = readySeller(['quota_per_period' => '5000'], $method);
    $exchange = app(SellerExchangeService::class)->create($member, $pack->load('version'), $quantity);
    app(ValidationDesk::class)->confirm(test()->validator, $exchange, $quantity, 'YEM-TX', null);
    app('auth')->forgetGuards();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return PayoutBatch::query()->where('payable_id', $exchange->id)->sole();
}

describe('members', function () {
    beforeEach(function () {
        $this->member = User::factory()->create(['first_name' => 'Yendoukoa', 'last_name' => 'NAKORE']);
        $this->member->pernumIdentities()->create(['pernum' => '1001043953', 'kind' => 'personal', 'status' => 'declared']);
        $this->other = User::factory()->create(['first_name' => 'Awa', 'last_name' => 'KOFFI']);
    });

    it('lists the members and finds them by Pernum', function () {
        $this->actingAs($this->admin, 'web');

        Livewire::test(ListMembers::class)
            ->assertCanSeeTableRecords([$this->member, $this->other])
            ->searchTable('1001043953')
            ->assertCanSeeTableRecords([$this->member])
            ->assertCanNotSeeTableRecords([$this->other]);
    });

    it('never lists staff accounts as members', function () {
        $this->actingAs($this->admin, 'web');

        Livewire::test(ListMembers::class)->assertCanNotSeeTableRecords([$this->validator]);
    });

    it('is closed to accounts without the permission', function () {
        $this->actingAs($this->validator, 'web')->get('/admin/membres')->assertForbidden();
    });

    it('opens the page of a member', function () {
        $this->actingAs($this->admin, 'web')->get("/admin/membres/{$this->member->id}")->assertOk()->assertSee('Yendoukoa NAKORE')->assertSee('1001043953');
    });

    it('suspends and reactivates an account after the identity is confirmed', function () {
        $this->actingAs($this->admin, 'web')->withSession(stepUpSession());

        Livewire::test(ViewMember::class, ['record' => $this->member->getKey()])
            ->callAction('suspend', ['reason' => 'Fraude suspectée'])
            ->assertHasNoActionErrors();
        expect($this->member->refresh()->status)->toBe(UserStatus::Suspended);

        Livewire::test(ViewMember::class, ['record' => $this->member->getKey()])
            ->callAction('reactivate', ['reason' => 'Vérification terminée']);
        expect($this->member->refresh()->status)->toBe(UserStatus::Active);
    });

    it('does not suspend without the confirmation code', function () {
        $this->actingAs($this->admin, 'web');

        Livewire::test(ViewMember::class, ['record' => $this->member->getKey()])
            ->callAction('suspend', ['reason' => 'Fraude suspectée', 'step_up_code' => '000000']);

        expect($this->member->refresh()->status)->toBe(UserStatus::Active);
    });
});

describe('payouts', function () {
    it('lists the payouts and opens one', function () {
        $batch = payoutFor('1500', 'FLOOZ');
        $this->actingAs($this->accounting, 'web');

        Livewire::test(ListPayouts::class)->assertCanSeeTableRecords([$batch]);
        $this->get("/admin/versements/{$batch->id}")->assertOk()->assertSee($batch->reference);
    });

    it('sends the transfers once the identity is confirmed', function () {
        $batch = payoutFor('1000');
        $this->actingAs($this->accounting, 'web')->withSession(stepUpSession());

        Livewire::test(ViewPayout::class, ['record' => $batch->getKey()])->callAction('dispatch');

        expect($batch->refresh()->status->value)->not->toBe('awaiting_dispatch');
    });

    it('does not send the transfers without confirmation', function () {
        $batch = payoutFor('1000');
        $this->actingAs($this->accounting, 'web');

        Livewire::test(ViewPayout::class, ['record' => $batch->getKey()])->callAction('dispatch', ['step_up_code' => '000000']);

        expect($batch->refresh()->status->value)->toBe('awaiting_dispatch');
    });

    it('records a manual settlement that a second person approves', function () {
        $batch = payoutFor('4000', 'CASH');
        $this->actingAs($this->accounting, 'web')->withSession(stepUpSession());

        Livewire::test(ViewPayout::class, ['record' => $batch->getKey()])
            ->callAction('settle', ['method' => 'CASH', 'amount' => $batch->remaining_amount, 'reference' => 'Reçu 12', 'paid_at' => now()->toDateTimeString()]);

        $settlement = ManualSettlement::query()->where('payout_batch_id', $batch->id)->sole();
        expect($settlement->status)->toBe(ManualSettlementStatus::PendingApproval);

        $this->actingAs($this->checker, 'web')->withSession(stepUpSession());
        Livewire::test(ListSettlements::class)->callTableAction('approve', $settlement);

        expect($settlement->refresh()->status)->toBe(ManualSettlementStatus::Applied);
    });

    it('refuses that the same person approves their own settlement', function () {
        $batch = payoutFor('4000', 'CASH');
        $this->actingAs($this->accounting, 'web')->withSession(stepUpSession());

        Livewire::test(ViewPayout::class, ['record' => $batch->getKey()])
            ->callAction('settle', ['method' => 'CASH', 'amount' => $batch->remaining_amount, 'reference' => 'Reçu 13', 'paid_at' => now()->toDateTimeString()]);
        $settlement = ManualSettlement::query()->where('payout_batch_id', $batch->id)->sole();

        Livewire::test(ListSettlements::class)->callTableAction('approve', $settlement);

        expect($settlement->refresh()->status)->toBe(ManualSettlementStatus::PendingApproval);
    });
});

it('shows the reports to those who may read them', function () {
    $this->actingAs($this->admin, 'web')->get('/admin/reports')->assertOk()->assertSee('Rapports')->assertSee('Total collecté');
    $this->actingAs($this->validator, 'web')->get('/admin/reports')->assertForbidden();
});
