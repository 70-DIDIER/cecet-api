<?php

use App\Domain\Backoffice\Http\Controllers\AuthController;
use App\Domain\Backoffice\Http\Controllers\DashboardController;
use App\Domain\Backoffice\Http\Controllers\FinanceController;
use App\Domain\Backoffice\Http\Controllers\MembersController;
use App\Domain\Backoffice\Http\Controllers\StepUpController;
use App\Domain\Backoffice\Http\Controllers\ValidationsController;
use App\Domain\Backoffice\Http\Middleware\AddBackofficeSecurityHeaders;
use App\Domain\Backoffice\Http\Middleware\EnsureBackofficeAccess;
use App\Domain\Backoffice\Http\Middleware\RequireBackofficeStepUp;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Back-office (Blade) — /backoffice
|--------------------------------------------------------------------------
|
| Loaded with the "web" middleware group (cookie session, CSRF). The pages are for active internal accounts only;
| each section adds the permission middleware of its own routes.
|
*/

Route::middleware(AddBackofficeSecurityHeaders::class)->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-public')->name('login.submit');
    Route::get('verification', [AuthController::class, 'showOtp'])->name('otp');
    Route::post('verification', [AuthController::class, 'verifyOtp'])->middleware('throttle:auth-otp')->name('otp.verify');
    Route::post('verification/renvoyer', [AuthController::class, 'resendOtp'])->middleware('throttle:auth-otp')->name('otp.resend');

    Route::middleware(EnsureBackofficeAccess::class)->group(function () {
        Route::post('deconnexion', [AuthController::class, 'logout'])->name('logout');

        Route::get('confirmation', [StepUpController::class, 'show'])->name('step-up');
        Route::post('confirmation/envoyer', [StepUpController::class, 'send'])->middleware('throttle:auth-otp')->name('step-up.send');
        Route::post('confirmation', [StepUpController::class, 'verify'])->middleware('throttle:auth-otp')->name('step-up.verify');

        Route::get('/', DashboardController::class)->name('dashboard');

        // Finance : versements de contre-valeur et règlements manuels.
        Route::prefix('finance')->name('finance.')->group(function () {
            Route::get('versements', [FinanceController::class, 'index'])->middleware('can:payouts.view')->name('payouts');
            Route::get('versements/{payout}', [FinanceController::class, 'show'])->middleware('can:payouts.view')->name('payout');
            Route::post('versements/{payout}/actualiser', [FinanceController::class, 'refresh'])->middleware('can:payouts.view')->name('payout.refresh');
            Route::get('reglements', [FinanceController::class, 'settlements'])->middleware('can:payouts.view')->name('settlements');

            Route::middleware(RequireBackofficeStepUp::class)->group(function () {
                Route::post('versements/{payout}/envoyer', [FinanceController::class, 'dispatch'])->middleware('can:payouts.retry')->name('payout.dispatch');
                Route::post('versements/{payout}/relancer', [FinanceController::class, 'retry'])->middleware('can:payouts.retry')->name('payout.retry');
                Route::post('versements/{payout}/reglement', [FinanceController::class, 'recordSettlement'])->middleware('can:payouts.settle_manual')->name('payout.settle');
                Route::post('reglements/{settlement}/approuver', [FinanceController::class, 'approveSettlement'])->middleware('can:payouts.approve_manual')->name('settlement.approve');
                Route::post('reglements/{settlement}/rejeter', [FinanceController::class, 'rejectSettlement'])->middleware('can:payouts.approve_manual')->name('settlement.reject');
            });
        });

        // Membres.
        Route::get('membres', [MembersController::class, 'index'])->middleware('can:members.view')->name('members');
        Route::get('membres/{user}', [MembersController::class, 'show'])->middleware('can:members.view')->name('members.show');
        Route::middleware(RequireBackofficeStepUp::class)->group(function () {
            Route::post('membres/{user}/suspendre', [MembersController::class, 'suspend'])->middleware('can:members.manage')->name('members.suspend');
            Route::post('membres/{user}/reactiver', [MembersController::class, 'reactivate'])->middleware('can:members.manage')->name('members.reactivate');
        });

        // Validations: paiements hors prestataire, YEM reçus, paiements marchands.
        Route::get('validations', [ValidationsController::class, 'index'])->name('validations');
        Route::get('validations/yem/{exchange}', [ValidationsController::class, 'showExchange'])->middleware('can:yem_validations.view')->name('validations.exchange');

        Route::middleware(RequireBackofficeStepUp::class)->prefix('validations')->name('validations.')->group(function () {
            Route::post('paiements/{attempt}/valider', [ValidationsController::class, 'approvePayment'])->middleware('can:payments.validate_manual')->name('payments.approve');
            Route::post('paiements/{attempt}/rejeter', [ValidationsController::class, 'rejectPayment'])->middleware('can:payments.validate_manual')->name('payments.reject');
            Route::post('yem/{exchange}/confirmer', [ValidationsController::class, 'confirmExchange'])->middleware('can:yem_validations.confirm')->name('exchange.confirm');
            Route::post('yem/{exchange}/rejeter', [ValidationsController::class, 'rejectExchange'])->middleware('can:yem_validations.confirm')->name('exchange.reject');
            Route::post('yem/{exchange}/litige', [ValidationsController::class, 'disputeExchange'])->middleware('can:yem_validations.dispute')->name('exchange.dispute');
            Route::post('marchands/{payment}/confirmer', [ValidationsController::class, 'confirmMerchantPayment'])->middleware('can:yem_validations.confirm')->name('merchant.confirm');
            Route::post('marchands/{payment}/rejeter', [ValidationsController::class, 'rejectMerchantPayment'])->middleware('can:yem_validations.confirm')->name('merchant.reject');
        });
    });
});
