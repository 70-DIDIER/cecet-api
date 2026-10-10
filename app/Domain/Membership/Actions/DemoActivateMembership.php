<?php

namespace App\Domain\Membership\Actions;

use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Membership\Enums\ActivationMode;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\MembershipFee;
use App\Domain\Membership\Models\Membership;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;

/**
 * DEMO_GATEWAY_BYPASS: activates an account without any payment, to validate feasibility in a demo environment.
 *
 * Technically impossible in production: the route is not even registered there, and this action refuses to run
 * in the production environment whatever the setting says.
 */
class DemoActivateMembership
{
    public function __construct(
        private readonly Application $app,
        private readonly SettingsService $settings,
        private readonly MembershipFee $fees,
        private readonly ActivateMembership $activate,
        private readonly SecurityEventLogger $securityLogger,
    ) {}

    public function handle(User $member): Membership
    {
        if (! $this->app->environment('testing') || ! $this->settings->boolean('platform.demo_gateway_bypass')) {
            throw PaymentException::demoUnavailable();
        }

        if (! $member->isMember() || $member->status !== UserStatus::PendingPayment) {
            throw PaymentException::notPayable();
        }

        return DB::transaction(function () use ($member): Membership {
            $fee = $this->fees->for($member);

            $membership = Membership::query()->firstOrCreate(
                ['user_id' => $member->id],
                [
                    'status' => MembershipStatus::PendingPayment,
                    'fee_amount' => $fee['amount'],
                    'fee_currency' => MembershipFee::CURRENCY,
                    'fee_setting_key' => $fee['setting_key'],
                ],
            );

            $this->activate->handle($membership, ActivationMode::DemoGatewayBypass);
            $this->securityLogger->record('membership.demo_gateway_bypass', SecuritySeverity::Warning, $member->id);

            return $membership->refresh();
        });
    }
}

[executed on device: ip-172-31-20-3 (266007aa-ed81-449a-969b-4ac5ea49fe76)]