<?php

use App\Domain\Identity\Http\Middleware\EnsureStaff;
use App\Domain\Identity\Http\Middleware\EnsureStepUp;
use App\Domain\Identity\Http\Middleware\EnsureSuperadminSession;
use App\Domain\Identity\Http\Middleware\EnsureStaffGuardianSession;
use App\Domain\Identity\Http\Middleware\RequireSuperadminMtls;
use App\Domain\Shared\Http\ApiErrorRenderer;
use App\Domain\Shared\Http\Middleware\AddSecurityHeaders;
use App\Domain\Shared\Http\Middleware\AttachCorrelationId;
use App\Domain\Shared\Http\Middleware\EnsureIdempotency;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')->prefix('backoffice')->name('backoffice.')->group(base_path('routes/backoffice.php'));
        },
    )
    ->withCommands([
        __DIR__.'/../app/Domain/Access/Console',
        __DIR__.'/../app/Domain/Audit/Console',
        __DIR__.'/../app/Domain/Exchange/Console',
        __DIR__.'/../app/Domain/Merchant/Console',
        __DIR__.'/../app/Domain/Ops/Console',
        __DIR__.'/../app/Domain/Payment/Console',
        __DIR__.'/../app/Domain/Payout/Console',
        __DIR__.'/../app/Domain/Quota/Console',
        __DIR__.'/../app/Domain/Reporting/Console',
        __DIR__.'/../app/Domain/Settings/Console',
        __DIR__.'/../app/Domain/Shared/Console',
        __DIR__.'/../app/Domain/Subscription/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AttachCorrelationId::class);

        // The React site authenticates with HttpOnly session cookies (Sanctum SPA); other clients use bearer tokens.
        $middleware->statefulApi();
        // Never attempt to redirect an unauthenticated API request to the
        // non-existent route named "login". API guests receive normalized 401s.
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : route('backoffice.login'),
        );
        $middleware->append(AddSecurityHeaders::class);

        $middleware->alias([
            'idempotent' => EnsureIdempotency::class,
            'step-up' => EnsureStepUp::class,
            'staff' => EnsureStaff::class,
            'superadmin.mtls' => RequireSuperadminMtls::class,
            'superadmin.session' => EnsureSuperadminSession::class,
            'staff.guardian' => EnsureStaffGuardianSession::class,
        ]);

        // Idempotency keys are scoped per user, so authentication must run first.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureIdempotency::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(new ApiErrorRenderer);
    })->create();
