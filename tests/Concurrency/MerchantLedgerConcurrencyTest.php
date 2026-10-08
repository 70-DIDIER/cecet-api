<?php

use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Merchant\MerchantLedger;
use App\Domain\Merchant\Models\MerchantAccount;
use App\Domain\Merchant\Models\MerchantLedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Real concurrency on the merchant ledger
|--------------------------------------------------------------------------
|
| Same harness as the quota ledger: independent PHP processes released at the same instant, committed data,
| schema rebuilt before and after.
|
*/

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--force' => true]);
    seedCatalogs();
    Mail::fake();

    $this->pack = subscribeAndPay(User::factory()->legalPerson()->create(), makePlan(PackFamily::Marchand, 'BRONZE', periodicTerms(['eligible_person_types' => ['legal']])));
});

afterEach(function () {
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/**
 * Runs the same merchant ledger operation in $count processes at the same instant; returns each outcome.
 *
 * @return list<string>
 */
function merchantLedgerInParallel(string $packId, int $count, string $operation, string $quantity): array
{
    $startAt = microtime(true) + 9;

    $processes = array_map(function (int $index) use ($packId, $operation, $quantity, $startAt): Process {
        $code = sprintf(
            <<<'PHP'
            while (microtime(true) < %s) { usleep(200); }
            try {
                app(App\Domain\Merchant\MerchantLedger::class)->%s(App\Domain\Subscription\Models\UserPack::query()->findOrFail('%s'), '%s', 'race:%s:%d');
                echo '#OK#';
            } catch (App\Domain\Shared\Exceptions\ApiException $e) {
                echo '#' . $e->errorCode . '#';
            }
            PHP,
            var_export($startAt, true),
            $operation,
            $packId,
            $quantity,
            $operation,
            $index,
        );

        $process = new Process(['php', 'artisan', 'tinker', '--execute', $code], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => config('database.connections.pgsql.database'),
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ]);
        $process->setTimeout(90);
        $process->start();

        return $process;
    }, range(1, $count));

    return array_map(function (Process $process): string {
        $process->wait();
        $output = $process->getOutput().$process->getErrorOutput();

        expect(preg_match('/#([A-Z_]+)#/', $output, $matches))->toBe(1, "Unexpected child output: {$output}");

        return $matches[1];
    }, $processes);
}

it('opens the account once and loses no credit when the first collections race', function () {
    $outcomes = merchantLedgerInParallel($this->pack->id, 8, 'credit', '12.5');

    expect(array_count_values($outcomes))->toBe(['OK' => 8])
        ->and(MerchantAccount::query()->findOrFail($this->pack->id)->eligible)->toBe('100.000000')
        ->and(MerchantLedgerEntry::query()->count())->toBe(8)
        ->and(Artisan::call('ledger:verify'))->toBe(0);
});

it('never redeems the same collections twice when reservations race', function () {
    app(MerchantLedger::class)->credit($this->pack, '100', 'collections');

    $outcomes = array_count_values(merchantLedgerInParallel($this->pack->id, 10, 'reserve', '15'));

    // 100 eligible, 15 per reservation: exactly 6 can win whatever the interleaving.
    expect($outcomes['OK'] ?? 0)->toBe(6)
        ->and($outcomes['INSUFFICIENT_MERCHANT_BALANCE'] ?? 0)->toBe(4);

    $account = MerchantAccount::query()->findOrFail($this->pack->id);
    expect($account->eligible)->toBe('10.000000')
        ->and($account->reserved)->toBe('90.000000')
        ->and(Artisan::call('ledger:verify'))->toBe(0);
});
