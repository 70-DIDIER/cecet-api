<?php

namespace App\Providers;

use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Otp\EmailOtpChannel;
use App\Domain\Identity\Otp\OtpChannel;
use App\Domain\Membership\Models\Membership;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Domain\Merchant\Models\MerchantQrCode;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Payout\Actions\SendPayoutBatch;
use App\Domain\Payout\Models\ManualSettlement;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Payout\Models\PayoutDestination;
use App\Domain\Payout\PayoutPlanner;
use App\Domain\Shared\Outbox\Outbox;
use App\Domain\Subscription\Models\PackTopup;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OtpChannel::class, EmailOtpChannel::class);

        // The outbox keeps its handler registry: one instance per application.
        $this->app->singleton(Outbox::class, function (Application $app): Outbox {
            $outbox = new Outbox($app, $app->make(SecurityEventLogger::class));
            $outbox->register(PayoutPlanner::OUTBOX_DISPATCH, SendPayoutBatch::class);

            return $outbox;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Stable aliases stored in polymorphic columns and audit subjects instead of class names.
        Relation::morphMap([
            'membership' => Membership::class,
            'payment_intent' => PaymentIntent::class,
            'payment_attempt' => PaymentAttempt::class,
            'pack_plan' => PackPlan::class,
            'user_pack' => UserPack::class,
            'pack_topup' => PackTopup::class,
            'exchange_request' => ExchangeRequest::class,
            'merchant_payment' => MerchantPayment::class,
            'merchant_qr_code' => MerchantQrCode::class,
            'payout_batch' => PayoutBatch::class,
            'manual_settlement' => ManualSettlement::class,
            'payout_destination' => PayoutDestination::class,
        ]);

        // The Superadmin holds every permission implicitly; business invariants are enforced in the domain.
        Gate::before(fn (mixed $user): ?bool => $user instanceof User && $user->isSuperadmin() ? true : null);

        $this->configureRateLimiting();
    }

    /**
     * Technical anti-abuse limits on public endpoints. Business limits (login lockout, OTP sends) are settings.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth-public', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('auth-otp', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by((string) $request->ip()));
        RateLimiter::for('api-user', fn (Request $request) => Limit::perMinute(120)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
