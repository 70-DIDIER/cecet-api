<?php

use App\Domain\Access\Http\Controllers\AccountController;
use App\Domain\Access\Http\Controllers\MemberOverviewController;
use App\Domain\Access\Http\Controllers\RoleController;
use App\Domain\Audit\Http\Controllers\AuditEventController;
use App\Domain\Catalog\Http\Controllers\PackPlanAdminController;
use App\Domain\Exchange\Http\Controllers\MemberExchangeController;
use App\Domain\Exchange\Http\Controllers\ValidationController;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Http\Controllers\LoginController;
use App\Domain\Identity\Http\Controllers\OtpController;
use App\Domain\Identity\Http\Controllers\PasswordResetController;
use App\Domain\Identity\Http\Controllers\RegistrationController;
use App\Domain\Identity\Http\Controllers\SessionController;
use App\Domain\Identity\Http\Controllers\StaffActivationController;
use App\Domain\Identity\Http\Controllers\StepUpController;
use App\Domain\Membership\Http\Controllers\MemberDashboardController;
use App\Domain\Membership\Http\Controllers\MembershipController;
use App\Domain\Merchant\Http\Controllers\MemberMerchantPaymentController;
use App\Domain\Merchant\Http\Controllers\MerchantAdminController;
use App\Domain\Merchant\Http\Controllers\MerchantController;
use App\Domain\Merchant\Http\Controllers\MerchantDirectoryAdminController;
use App\Domain\Merchant\Http\Controllers\MerchantDirectoryController;
use App\Domain\Merchant\Http\Controllers\MerchantPaymentValidationController;
use App\Domain\Ops\Http\Controllers\OpsHealthController;
use App\Domain\Payment\Http\Controllers\AdminPaymentController;
use App\Domain\Payment\Http\Controllers\PaymentWebhookController;
use App\Domain\Payment\Http\Controllers\ValidationQueueController;
use App\Domain\Payout\Http\Controllers\FinanceController;
use App\Domain\Payout\Http\Controllers\PayoutDestinationController;
use App\Domain\Reporting\Http\Controllers\DashboardController;
use App\Domain\Reporting\Http\Controllers\ImportController;
use App\Domain\Reporting\Http\Controllers\ReportController;
use App\Domain\Reporting\Http\Controllers\SnapshotController;
use App\Domain\Settings\Http\Controllers\PublicConfigController;
use App\Domain\Settings\Http\Controllers\SettingController;
use App\Domain\Shared\Http\Controllers\HealthController;
use App\Domain\Subscription\Http\Controllers\MemberPackController;
use App\Domain\Subscription\Http\Controllers\UserPackAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');

    /*
     * Public authentication flows.
     */
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::middleware('throttle:auth-public')->group(function () {
            Route::post('register', [RegistrationController::class, 'store'])->middleware('idempotent')->name('register');
            Route::post('register/check', [RegistrationController::class, 'check'])->name('register.check');
            Route::post('login', [LoginController::class, 'store'])->name('login');
            Route::post('staff/activate', StaffActivationController::class)->name('staff.activate');
            Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->name('password.forgot');
        });

        Route::middleware('throttle:auth-otp')->group(function () {
            Route::post('email/verify', [RegistrationController::class, 'verifyEmail'])->name('email.verify');
            Route::post('login/verify', [LoginController::class, 'verifyOtp'])->name('login.verify');
            Route::post('mfa/verify', [LoginController::class, 'verifyOtp'])->name('mfa.verify');
            Route::post('otp/resend', [OtpController::class, 'resend'])->name('otp.resend');
            Route::post('password/reset', [PasswordResetController::class, 'reset'])->name('password.reset');
        });
    });

    /*
     * Public pack catalog (the price to pay is personalised when a member is signed in).
     */
    Route::middleware('throttle:api-user')->group(function () {
        Route::get('config', PublicConfigController::class)->name('config');
        Route::get('packs', [MemberPackController::class, 'catalog'])->name('packs.index');
        Route::get('packs/{plan}', [MemberPackController::class, 'showPlan'])->name('packs.show');
        Route::get('merchants', [MerchantDirectoryController::class, 'index'])->middleware('throttle:60,1')->name('merchants.index');
    });

    /*
     * Payment provider callbacks: authenticated by signature, not by session.
     */
    Route::post('webhooks/payments/{provider}', PaymentWebhookController::class)
        ->whereIn('provider', ['fake', 'fedapay'])
        ->middleware('throttle:webhooks')
        ->name('webhooks.payments');

    /*
     * Authenticated account (members and staff).
     */
    Route::middleware(['auth:sanctum', 'throttle:api-user'])->group(function () {
        Route::post('auth/logout', [SessionController::class, 'logout'])->name('auth.logout');
        Route::post('auth/step-up', [StepUpController::class, 'store'])->middleware('throttle:auth-otp')->name('auth.step-up');
        Route::post('auth/step-up/verify', [StepUpController::class, 'confirm'])->middleware('throttle:auth-otp')->name('auth.step-up.verify');

        Route::get('me', [SessionController::class, 'me'])->name('me');
        Route::get('me/sessions', [SessionController::class, 'index'])->name('me.sessions.index');
        Route::post('me/sessions/revoke-others', [SessionController::class, 'destroyOthers'])->name('me.sessions.revoke-others');
        Route::delete('me/sessions/{session}', [SessionController::class, 'destroy'])->whereNumber('session')->name('me.sessions.destroy');

        /*
         * Membership of the authenticated member.
         */
        Route::get('me/dashboard', MemberDashboardController::class)->name('me.dashboard');
        Route::get('me/membership', [MembershipController::class, 'show'])->name('me.membership.show');
        Route::post('me/membership/payments', [MembershipController::class, 'startPayment'])->middleware('idempotent')->name('me.membership.payments.store');
        Route::get('me/membership/payments/{attempt}', [MembershipController::class, 'showAttempt'])->name('me.membership.payments.show');
        Route::post('me/membership/payments/{attempt}/refresh', [MembershipController::class, 'refreshAttempt'])->middleware('throttle:10,1')->name('me.membership.payments.refresh');
        Route::post('me/payments/{attempt}/declare', [MembershipController::class, 'declarePayment'])->name('me.payments.declare');

        // Presentation-only endpoints exist in automated tests and staging, never in production.
        if (app()->environment(['testing', 'staging'])) {
            Route::post('me/membership/demo-activation', [MembershipController::class, 'demoActivate'])->name('me.membership.demo-activation');
            Route::post('me/payments/{attempt}/demo-settle', [MembershipController::class, 'demoSettle'])->name('me.payments.demo-settle');
        }

        /*
         * Pack catalog and the packs held by the member.
         * The authenticated catalog hides plans that are not eligible for the member's person type.
         */
        Route::get('me/catalog-packs', [MemberPackController::class, 'memberCatalog'])->name('me.packs.catalog');
        Route::get('me/packs', [MemberPackController::class, 'index'])->name('me.packs.index');
        Route::post('me/packs', [MemberPackController::class, 'subscribe'])->middleware('idempotent')->name('me.packs.store');
        Route::get('me/packs/{userPack}', [MemberPackController::class, 'show'])->name('me.packs.show');
        Route::get('me/packs/{userPack}/topups', [MemberPackController::class, 'topups'])->name('me.packs.topups.index');
        Route::post('me/packs/{userPack}/topups', [MemberPackController::class, 'topup'])->middleware('idempotent')->name('me.packs.topups.store');
        Route::get('me/quota-ledger', [MemberPackController::class, 'ledger'])->name('me.quota-ledger');

        /*
         * Selling YEM to the CECT (Pack Vendeur) and the member's payout destination.
         */
        Route::get('me/payout-destination', [PayoutDestinationController::class, 'show'])->name('me.payout-destination.show');
        Route::put('me/payout-destination', [PayoutDestinationController::class, 'update'])->middleware('step-up')->name('me.payout-destination.update');
        Route::get('me/exchange-requests', [MemberExchangeController::class, 'index'])->name('me.exchanges.index');
        Route::post('me/exchange-requests', [MemberExchangeController::class, 'store'])->middleware('idempotent')->name('me.exchanges.store');
        Route::get('me/exchange-requests/{exchange}', [MemberExchangeController::class, 'show'])->name('me.exchanges.show');
        Route::post('me/exchange-requests/{exchange}/declare-sent', [MemberExchangeController::class, 'declareSent'])->name('me.exchanges.declare-sent');
        Route::post('me/exchange-requests/{exchange}/cancel', [MemberExchangeController::class, 'cancel'])->name('me.exchanges.cancel');

        /*
         * Paying a merchant of the network (Consommateur/Personnel -> Marchand) and the merchant's own area.
         */
        Route::get('merchant-payments/qr/{token}', [MemberMerchantPaymentController::class, 'resolve'])->middleware('throttle:30,1')->name('merchant-payments.resolve');
        Route::post('merchant-payments/intents', [MemberMerchantPaymentController::class, 'store'])->middleware('idempotent')->name('merchant-payments.store');
        Route::get('me/merchant-payments', [MemberMerchantPaymentController::class, 'index'])->name('me.merchant-payments.index');
        Route::get('me/merchant-payments/{payment}', [MemberMerchantPaymentController::class, 'show'])->name('me.merchant-payments.show');
        Route::post('me/merchant-payments/{payment}/declare-sent', [MemberMerchantPaymentController::class, 'declareSent'])->name('me.merchant-payments.declare-sent');
        Route::post('me/merchant-payments/{payment}/cancel', [MemberMerchantPaymentController::class, 'cancel'])->name('me.merchant-payments.cancel');

        Route::prefix('merchant')->name('merchant.')->group(function () {
            Route::get('qr-codes', [MerchantController::class, 'qrCodes'])->name('qr-codes.index');
            Route::post('qr-codes', [MerchantController::class, 'issueQr'])->middleware('idempotent')->name('qr-codes.store');
            Route::post('qr-codes/{qr}/rotate', [MerchantController::class, 'rotateQr'])->middleware('step-up')->name('qr-codes.rotate');
            Route::post('qr-codes/{qr}/revoke', [MerchantController::class, 'revokeQr'])->name('qr-codes.revoke');
            Route::get('payments', [MerchantController::class, 'payments'])->name('payments.index');
            Route::post('payments/{payment}/confirm', [MerchantController::class, 'confirm'])->middleware('idempotent')->name('payments.confirm');
            Route::post('payments/{payment}/report-not-received', [MerchantController::class, 'reportNotReceived'])->name('payments.report-not-received');
            Route::get('balance', [MerchantController::class, 'balance'])->name('balance');
            Route::get('directory-profile', [MerchantDirectoryController::class, 'show'])->name('directory-profile.show');
            Route::put('directory-profile', [MerchantDirectoryController::class, 'update'])->name('directory-profile.update');
            Route::post('redemptions', [MerchantController::class, 'redeem'])->middleware('idempotent')->name('redemptions.store');
        });

        /*
         * Validator: confirm the YEM actually received on the CECT Pernum.
         */
        Route::prefix('validations')->name('validations.')->middleware('staff')->group(function () {
            Route::get('queue', [ValidationQueueController::class, 'index'])->name('queue');
            Route::post('payments/{attempt}/approve', [ValidationQueueController::class, 'approve'])->middleware(['can:payments.validate_manual', 'step-up', 'idempotent'])->name('payments.approve');
            Route::post('payments/{attempt}/reject', [ValidationQueueController::class, 'reject'])->middleware(['can:payments.validate_manual', 'step-up'])->name('payments.reject');
            Route::get('merchant-payments/{payment}', [MerchantPaymentValidationController::class, 'show'])->middleware('can:yem_validations.view')->name('merchant-payments.show');
            Route::post('merchant-payments/{payment}/confirm', [MerchantPaymentValidationController::class, 'confirm'])->middleware(['can:yem_validations.confirm', 'step-up', 'idempotent'])->name('merchant-payments.confirm');
            Route::post('merchant-payments/{payment}/reject', [MerchantPaymentValidationController::class, 'reject'])->middleware(['can:yem_validations.confirm', 'step-up'])->name('merchant-payments.reject');
            Route::get('pending', [ValidationController::class, 'pending'])->middleware('can:yem_validations.view')->name('pending');
            Route::get('{exchange}', [ValidationController::class, 'show'])->middleware('can:yem_validations.view')->name('show');
            Route::post('{exchange}/confirm', [ValidationController::class, 'confirm'])->middleware(['can:yem_validations.confirm', 'step-up', 'idempotent'])->name('confirm');
            Route::post('{exchange}/reject', [ValidationController::class, 'reject'])->middleware(['can:yem_validations.confirm', 'step-up'])->name('reject');
            Route::post('{exchange}/dispute', [ValidationController::class, 'dispute'])->middleware(['can:yem_validations.dispute', 'step-up'])->name('dispute');
        });

        /*
         * Accounting: counter-values owed in FCFA.
         */
        Route::prefix('finance')->name('finance.')->middleware('staff')->group(function () {
            Route::get('payouts', [FinanceController::class, 'index'])->middleware('can:payouts.view')->name('payouts.index');
            Route::get('payouts/{payout}', [FinanceController::class, 'show'])->middleware('can:payouts.view')->name('payouts.show');
            Route::post('payouts/{payout}/refresh', [FinanceController::class, 'refresh'])->middleware('can:payouts.view')->name('payouts.refresh');
            Route::post('payouts/{payout}/dispatch', [FinanceController::class, 'dispatch'])->middleware(['can:payouts.retry', 'step-up'])->name('payouts.dispatch');
            Route::post('payouts/{payout}/retry', [FinanceController::class, 'retry'])->middleware(['can:payouts.retry', 'step-up'])->name('payouts.retry');
            Route::post('payouts/{payout}/manual-settlements', [FinanceController::class, 'recordSettlement'])->middleware(['can:payouts.settle_manual', 'step-up', 'idempotent'])->name('payouts.settlements.store');
            Route::get('manual-settlements', [FinanceController::class, 'pendingSettlements'])->middleware('can:payouts.view')->name('settlements.pending');
            Route::post('manual-settlements/{settlement}/approve', [FinanceController::class, 'approveSettlement'])->middleware(['can:payouts.approve_manual', 'step-up'])->name('settlements.approve');
            Route::post('manual-settlements/{settlement}/reject', [FinanceController::class, 'rejectSettlement'])->middleware(['can:payouts.approve_manual', 'step-up'])->name('settlements.reject');
        });

        /*
         * Back-office: active internal accounts only, fine-grained permissions per route.
         */
        Route::prefix('admin')->name('admin.')->middleware('staff')->group(function () {
            Route::get('permissions', [RoleController::class, 'permissions'])->middleware('can:roles.view')->name('permissions.index');
            Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
            Route::middleware(['can:roles.manage', 'step-up'])->group(function () {
                Route::post('roles', [RoleController::class, 'store'])->middleware('idempotent')->name('roles.store');
                Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->name('roles.permissions.update');
                Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
            });

            foreach ([UserKind::Staff->value => 'staff', UserKind::Member->value => 'members'] as $kind => $segment) {
                Route::prefix($segment)->name("{$segment}.")->group(function () use ($kind, $segment) {
                    Route::get('/', [AccountController::class, 'index'])->middleware("can:{$segment}.view")->defaults('kind', $kind)->name('index');
                    Route::get('{user}', [AccountController::class, 'show'])->defaults('kind', $kind)->name('show');
                    Route::middleware(["can:{$segment}.manage", 'step-up'])->group(function () use ($kind) {
                        Route::post('{user}/suspend', [AccountController::class, 'suspend'])->defaults('kind', $kind)->name('suspend');
                        Route::post('{user}/reactivate', [AccountController::class, 'reactivate'])->defaults('kind', $kind)->name('reactivate');
                    });
                });
            }

            Route::get('members/{user}/overview', MemberOverviewController::class)->middleware('can:members.view')->name('members.overview');

            Route::middleware(['can:staff.manage', 'step-up'])->group(function () {
                Route::post('staff', [AccountController::class, 'storeStaff'])->middleware('idempotent')->name('staff.store');
                Route::put('staff/{user}/roles', [AccountController::class, 'updateRoles'])->name('staff.roles.update');
            });

            Route::get('settings', [SettingController::class, 'index'])->middleware('can:settings.view')->name('settings.index');
            Route::get('settings/{key}', [SettingController::class, 'show'])->middleware('can:settings.view')->name('settings.show');
            Route::put('settings/{key}', [SettingController::class, 'update'])->middleware(['step-up', 'idempotent'])->name('settings.update');

            Route::get('payments/attempts', [AdminPaymentController::class, 'attempts'])->middleware('can:payments.view')->name('payments.attempts');
            Route::get('payments/webhook-events', [AdminPaymentController::class, 'webhookEvents'])->middleware('can:payments.view')->name('payments.webhook-events');

            Route::get('packs/plans', [PackPlanAdminController::class, 'index'])->middleware('can:packs.view')->name('packs.plans.index');
            Route::get('packs/plans/{plan}', [PackPlanAdminController::class, 'show'])->middleware('can:packs.view')->name('packs.plans.show');
            Route::middleware(['can:packs.manage', 'step-up'])->group(function () {
                Route::post('packs/plans', [PackPlanAdminController::class, 'store'])->middleware('idempotent')->name('packs.plans.store');
                Route::post('packs/plans/{plan}/versions', [PackPlanAdminController::class, 'storeVersion'])->middleware('idempotent')->name('packs.versions.store');
                Route::post('packs/plans/{plan}/versions/{version}/publish', [PackPlanAdminController::class, 'publishVersion'])->scopeBindings()->name('packs.versions.publish');
                Route::delete('packs/plans/{plan}/versions/{version}', [PackPlanAdminController::class, 'destroyVersion'])->scopeBindings()->name('packs.versions.destroy');
                Route::post('packs/plans/{plan}/transition', [PackPlanAdminController::class, 'transition'])->name('packs.plans.transition');
                Route::post('packs/plans/{plan}/duplicate', [PackPlanAdminController::class, 'duplicate'])->middleware('idempotent')->name('packs.plans.duplicate');
            });

            Route::get('user-packs', [UserPackAdminController::class, 'index'])->middleware('can:user_packs.view')->name('user-packs.index');
            Route::get('user-packs/{userPack}', [UserPackAdminController::class, 'show'])->middleware('can:user_packs.view')->name('user-packs.show');
            Route::post('user-packs/{userPack}/adjustments', [UserPackAdminController::class, 'adjust'])->middleware(['can:quota.adjust', 'step-up', 'idempotent'])->name('user-packs.adjust');

            Route::get('dashboard', DashboardController::class)->name('dashboard');
            Route::get('overview', [ReportController::class, 'overview'])->middleware('can:reports.view')->name('overview');
            Route::get('reports/summary', [ReportController::class, 'summary'])->middleware('can:reports.view')->name('reports.summary');
            Route::get('reports/summary/print', [ReportController::class, 'printable'])->middleware('can:reports.view')->name('reports.summary.print');
            Route::get('reports/summary.pdf', [ReportController::class, 'pdf'])->middleware(['can:reports.export', 'step-up'])->name('reports.summary.pdf');
            Route::get('reports/summary.xlsx', [ReportController::class, 'xlsx'])->middleware(['can:reports.export', 'step-up'])->name('reports.summary.xlsx');
            Route::get('snapshots', [SnapshotController::class, 'index'])->middleware('can:reports.view')->name('snapshots.index');
            Route::post('snapshots', [SnapshotController::class, 'store'])->middleware(['can:reports.export', 'throttle:5,1'])->name('snapshots.store');
            Route::get('snapshots/{snapshot}/download', [SnapshotController::class, 'download'])->middleware(['can:reports.export', 'step-up'])->name('snapshots.download');
            Route::get('imports', [ImportController::class, 'index'])->middleware('can:imports.manage')->name('imports.index');
            Route::post('imports', [ImportController::class, 'store'])->middleware(['can:imports.manage', 'step-up', 'idempotent'])->name('imports.store');
            Route::get('imports/{import}', [ImportController::class, 'show'])->middleware('can:imports.manage')->name('imports.show');
            Route::post('imports/{import}/approve', [ImportController::class, 'approve'])->middleware(['can:imports.manage', 'step-up', 'idempotent'])->name('imports.approve');
            Route::post('imports/{import}/cancel', [ImportController::class, 'cancel'])->middleware('can:imports.manage')->name('imports.cancel');
            Route::get('exports/{type}', [ReportController::class, 'export'])->middleware(['can:reports.export', 'step-up'])->name('exports.show');

            Route::get('merchant-directory', [MerchantDirectoryAdminController::class, 'index'])->middleware('can:settings.view')->name('merchant-directory.index');
            Route::put('merchant-directory/{user}', [MerchantDirectoryAdminController::class, 'update'])->middleware(['can:settings.update', 'step-up'])->name('merchant-directory.update');

            Route::get('merchant-payments', [MerchantAdminController::class, 'payments'])->middleware('can:user_packs.view')->name('merchant-payments.index');
            Route::get('merchant-accounts/{userPack}', [MerchantAdminController::class, 'account'])->middleware('can:user_packs.view')->name('merchant-accounts.show');
            Route::post('merchant-accounts/{userPack}/adjustments', [MerchantAdminController::class, 'adjust'])->middleware(['can:quota.adjust', 'step-up', 'idempotent'])->name('merchant-accounts.adjust');

            Route::get('ops/health', OpsHealthController::class)->middleware('can:security_events.view')->name('ops.health');
            Route::get('audit-events', [AuditEventController::class, 'index'])->middleware('can:audit.view')->name('audit-events.index');
            Route::get('security-events', [AuditEventController::class, 'security'])->middleware('can:security_events.view')->name('security-events.index');
        });
    });
});
