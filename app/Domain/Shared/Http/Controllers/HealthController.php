<?php

namespace App\Domain\Shared\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Liveness and dependency check, without exposing internal details.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function (): void {
                $probe = 'health:'.Str::random(8);
                Cache::put($probe, true, 10);
                Cache::forget($probe);
            }),
        ];

        $isHealthy = ! in_array('fail', $checks, true);

        return new JsonResponse([
            'status' => $isHealthy ? 'ok' : 'degraded',
            'version' => config('app.version'),
            'checks' => $checks,
            'time' => now()->toIso8601ZuluString(),
        ], $isHealthy ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $exception) {
            report($exception);

            return 'fail';
        }
    }
}
