<?php

use App\Domain\Quota\Models\QuotaAccount;
use App\Domain\Quota\Models\QuotaLedgerEntry;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Real concurrency on the quota ledger
|--------------------------------------------------------------------------
|
| Each scenario launches independent PHP processes against the test database. They all wait for the same
| instant before touching the ledger, so their transactions genuinely overlap. The data is committed (no
| wrapping transaction) and the schema is rebuilt before and after to leave the database clean.
|
*/

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--force' => true]);
    seedCatalogs();
    Mail::fake();

    $this->pack = subscribeAndPay(User::factory()->create(), makePlan());
});

afterEach(function () {
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/**
 * Starts $count processes that reserve $quantity at the same instant, and returns what each one reported.
 *
 * @return list<array{database: string, outcome: string, entry: string}>
 */
function reserveInParallel(string $packId, int $count, string $quantity, Closure $keyFor): array
{
    $startAt = microtime(true) + 9;

    $processes = array_map(function (int $index) use ($packId, $quantity, $keyFor, $startAt): Process {
        $code = sprintf(
            <<<'PHP'
            while (microtime(true) < %s) { usleep(200); }
            try {
                $entry = app(App\Domain\Quota\QuotaLedger::class)->reserve(App\Domain\Subscription\Models\UserPack::query()->findOrFail('%s'), '%s', '%s');
                echo '#' . config('database.connections.pgsql.database') . '|OK|' . $entry->id . '#';
            } catch (App\Domain\Quota\Exceptions\QuotaException $e) {
                echo '#' . config('database.connections.pgsql.database') . '|' . $e->errorCode . '|#';
            }
            PHP,
            var_export($startAt, true),
            $packId,
            $quantity,
            $keyFor($index),
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

    return array_map(function (Process $process): array {
        $process->wait();
        $output = $process->getOutput().$process->getErrorOutput();

        expect(preg_match('/#([a-z_0-9]+)\|([A-Z_]+)\|([0-9a-z]*)#/', $output, $matches))->toBe(1, "Unexpected child output: {$output}");

        return ['database' => $matches[1], 'outcome' => $matches[2], 'entry' => $matches[3]];
    }, $processes);
}

it('never spends the same quota twice when reservations race (no double spend)', function () {
    $results = reserveInParallel($this->pack->id, 10, '20', fn (int $index) => "race:{$index}");

    $testDatabase = config('database.connections.pgsql.database');
    $outcomes = array_count_values(array_column($results, 'outcome'));

    // 125 available, 20 per reservation: exactly 6 can win whatever the interleaving.
    expect(array_unique(array_column($results, 'database')))->toBe([$testDatabase])
        ->and($outcomes['OK'] ?? 0)->toBe(6)
        ->and($outcomes['INSUFFICIENT_QUOTA'] ?? 0)->toBe(4);

    $account = QuotaAccount::query()->findOrFail($this->pack->id);
    expect($account->available)->toBe('5.000000')
        ->and($account->reserved)->toBe('120.000000')
        ->and(QuotaLedgerEntry::query()->where('user_pack_id', $this->pack->id)->where('type', 'reserve')->count())->toBe(6);

    expect(Artisan::call('ledger:verify'))->toBe(0);
})->group('concurrency');

it('executes an operation once when the same idempotency key races', function () {
    $results = reserveInParallel($this->pack->id, 8, '20', fn (int $index) => 'race:same-key');

    expect(array_unique(array_column($results, 'outcome')))->toBe(['OK'])
        ->and(count(array_unique(array_column($results, 'entry'))))->toBe(1);

    $account = QuotaAccount::query()->findOrFail($this->pack->id);
    expect($account->available)->toBe('105.000000')
        ->and($account->reserved)->toBe('20.000000')
        ->and(QuotaLedgerEntry::query()->where('idempotency_key', 'race:same-key')->count())->toBe(1);

    expect(Artisan::call('ledger:verify'))->toBe(0);
})->group('concurrency');

it('keeps the account consistent with its ledger after a mixed concurrent run', function () {
    $results = reserveInParallel($this->pack->id, 12, '10.5', fn (int $index) => "mixed:{$index}");
    $won = count(array_filter($results, fn (array $result): bool => $result['outcome'] === 'OK'));

    // 125 / 10.5 = 11.9: eleven reservations fit, the twelfth cannot.
    expect($won)->toBe(11);

    $account = UserPack::query()->findOrFail($this->pack->id)->account()->firstOrFail();
    expect($account->available)->toBe('9.500000')
        ->and($account->reserved)->toBe('115.500000')
        ->and(Artisan::call('ledger:verify'))->toBe(0);
})->group('concurrency');
