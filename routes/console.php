<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Every task is idempotent and protected against overlapping runs.
| The server needs a single cron entry: `* * * * * php artisan schedule:run`.
|
*/

Schedule::command('packs:roll-periods')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('packs:cancel-stale-pending')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('outbox:relay')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('payouts:refresh')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('exchanges:expire')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('ops:check')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('ops:check --integrity')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('snapshots:generate')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('merchant-payments:expire')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('ledger:verify')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
Schedule::command('audit:verify')->dailyAt('02:45')->withoutOverlapping()->onOneServer();
Schedule::command('model:prune')->daily()->onOneServer();
