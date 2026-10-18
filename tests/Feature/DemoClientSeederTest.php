<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Merchant\Models\MerchantAccount;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Database\Seeders\DemoClientSeeder;
use Illuminate\Support\Facades\Mail;

it('builds a complete, consistent history for the demonstration client', function () {
    Mail::fake();
    $this->seed(DemoClientSeeder::class);

    $client = User::query()->where('email', 'tandemodon55@gmail.com')->firstOrFail();

    expect($client->status->value)->toBe('active')
        ->and($client->pernumIdentities()->value('pernum'))->toBe('1009000055')
        ->and(UserPack::query()->where('user_id', $client->id)->where('status', 'active')->count())->toBe(3);

    // Ledgers still add up, and the audit chain is intact.
    $this->artisan('ledger:verify')->assertSuccessful();
    $this->artisan('audit:verify')->assertSuccessful();

    $packs = UserPack::query()->where('user_id', $client->id)->with(['plan', 'account'])->get()->keyBy(fn ($pack) => $pack->plan->code);
    expect($packs['C1']->account->consumed)->toBe('20.000000')
        ->and($packs['C1']->account->reserved)->toBe('10.000000')
        ->and($packs['V1']->account->consumed)->toBe('70.000000')
        ->and($packs['V1']->account->reserved)->toBe('30.000000')
        ->and($packs['PERSO']->account->available)->toBe('15.384615');

    $statuses = ExchangeRequest::query()->where('user_id', $client->id)->pluck('status')->map(fn ($status) => $status->value)->sort()->values()->all();
    expect($statuses)->toBe(['awaiting_transfer', 'completed', 'declared_sent', 'yem_received']);

    $payouts = PayoutBatch::query()->where('beneficiary_id', $client->id)->get()->keyBy('total_amount');
    expect($payouts[20400]->status->value)->toBe('paid')
        ->and($payouts[15300]->status->value)->toBe('awaiting_dispatch');

    expect(PaymentAttempt::query()->where('provider', 'manual')->where('status', 'pending')->count())->toBe(1)
        ->and(MerchantPayment::query()->pluck('status')->map(fn ($status) => $status->value)->sort()->values()->all())->toBe(['confirmed', 'declared_sent'])
        ->and(MerchantAccount::query()->sole()->eligible)->toBe('20.000000');

    // History spread over time, not all created "now".
    expect(AuditEvent::query()->min('occurred_at'))->toBeLessThan(now()->subDays(40));
});

it('does nothing when the client already exists, and never runs in production', function () {
    Mail::fake();
    $this->seed(DemoClientSeeder::class);
    $count = User::query()->count();

    $this->seed(DemoClientSeeder::class);
    expect(User::query()->count())->toBe($count);

    app()->detectEnvironment(fn () => 'production');
    expect(fn () => (new DemoClientSeeder)->run())->toThrow(RuntimeException::class);
});

it('adds the history to a member who registered by themselves, without touching their credentials', function () {
    Mail::fake();
    $member = User::factory()->status(UserStatus::PendingPayment)->create(['email' => 'tandemodson55@gmail.com', 'phone' => '+22891234567']);
    $member->pernumIdentities()->create(['pernum' => '1001555555', 'kind' => 'personal', 'status' => 'declared']);
    $passwordHash = $member->password;
    config(['demo.client_email' => 'tandemodson55@gmail.com']);

    $this->seed(DemoClientSeeder::class);

    $member->refresh();
    expect($member->status)->toBe(UserStatus::Active)
        ->and($member->password)->toBe($passwordHash)
        ->and($member->phone)->toBe('+22891234567')
        ->and($member->pernumIdentities()->value('pernum'))->toBe('1001555555')
        ->and($member->created_at)->toBeLessThan(now()->subDays(40))
        ->and(UserPack::query()->where('user_id', $member->id)->where('status', 'active')->count())->toBe(3)
        ->and(ExchangeRequest::query()->where('user_id', $member->id)->count())->toBe(4);

    $this->artisan('ledger:verify')->assertSuccessful();
    $this->artisan('audit:verify')->assertSuccessful();
});

it('gives a second client the same history and reuses the demonstration merchant', function () {
    Mail::fake();
    $this->seed(DemoClientSeeder::class);

    $second = User::factory()->status(UserStatus::PendingEmailVerification)->create(['email' => 'second@example.org']);
    $second->pernumIdentities()->create(['pernum' => '1001666666', 'kind' => 'personal', 'status' => 'declared']);
    config(['demo.client_email' => 'second@example.org']);

    $this->seed(DemoClientSeeder::class);

    expect($second->refresh()->status)->toBe(UserStatus::Active)
        ->and(User::query()->where('email', 'marchand.demo@cect.example')->count())->toBe(1)
        ->and(MerchantPayment::query()->count())->toBe(4)
        ->and(MerchantAccount::query()->sole()->eligible)->toBe('40.000000');

    $this->artisan('ledger:verify')->assertSuccessful();
});

it('leaves an already active account alone', function () {
    Mail::fake();
    $member = User::factory()->create(['email' => 'actif@example.org']);
    config(['demo.client_email' => 'actif@example.org']);

    $this->seed(DemoClientSeeder::class);

    expect(UserPack::query()->where('user_id', $member->id)->exists())->toBeFalse();
});
