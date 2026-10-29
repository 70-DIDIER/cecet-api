<?php

namespace Database\Seeders;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Catalog\CatalogManager;
use App\Domain\Catalog\Enums\PackPlanStatus;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Exchange\Actions\SellerExchangeService;
use App\Domain\Exchange\Actions\ValidationDesk;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Identity\Actions\RegisterMember;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Kyc\KycEligibility;
use App\Domain\Kyc\Models\MemberKycCase;
use App\Domain\Merchant\Actions\MerchantPaymentService;
use App\Domain\Merchant\Actions\MerchantQrService;
use App\Domain\Merchant\Models\MerchantDirectoryProfile;
use App\Domain\Merchant\Models\MerchantQrCode;
use App\Domain\Payment\Actions\ManualPaymentDesk;
use App\Domain\Payment\Actions\StartMembershipPayment;
use App\Domain\Payout\Actions\ManualSettlementService;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Payout\Models\PayoutDestination;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\Models\SettingVersion;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Actions\RequestPackTopup;
use App\Domain\Subscription\Actions\SubscribeToPack;
use App\Domain\Subscription\Models\PackPeriod;
use App\Domain\Subscription\Models\UserPack;
use App\Domain\Subscription\PeriodRoller;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Demonstration data for a client of the cooperative: a member with a complete, realistic history, so that every
 * back-office screen has something to show.
 *
 * Opt-in, never in production: php artisan db:seed --class=DemoClientSeeder
 * (the client's e-mail can be changed with DEMO_CLIENT_EMAIL, the common password with DEMO_PASSWORD: config/demo.php).
 *
 * Everything goes through the real services (registration, manual payments, subscriptions, quota ledger, exchanges,
 * payouts, merchant payments), with the clock moved back step by step: ledgers, audit trail and statuses are the ones
 * the application itself would have produced. No e-mail is sent.
 */
class DemoClientSeeder extends Seeder
{
    private User $admin;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Le seeder de démonstration est interdit en production.');
        }

        $email = mb_strtolower((string) config('demo.client_email'));
        $password = (string) config('demo.password');

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && ! $this->canEnrich($existing)) {
            $this->command->warn("Le compte {$email} existe déjà et n'est pas en attente d'activation : rien n'a été modifié.");

            return;
        }

        Mail::fake();
        Artisan::call('settings:sync');
        $this->call([AccessSeeder::class, LegacyCatalogSeeder::class]);

        $settings = app(SettingsService::class);
        $this->admin = $this->demoStaff($password);

        if (blank($settings->string('yem.cect_pernum'))) {
            $settings->set('yem.cect_pernum', '1000000001', 'Pernum de réception de démonstration');
        }

        $start = CarbonImmutable::now();
        $this->backdateSettings($start->subDays(60));

        try {
            $this->at($start->subDays(46));
            $this->publishDemoPlans();

            $this->at($start->subDays(45));
            $client = $existing !== null
                ? $this->enrichedMember($existing, $start->subDays(45))
                : $this->activatedMember($email, $password, [
                    'person_type' => 'natural', 'first_name' => 'Client', 'last_name' => 'Démo', 'phone' => '+22890000055',
                    'pernum' => '1009000055', 'city' => 'Lomé', 'preferred_payment_method' => 'CASH',
                ]);

            $this->at($start->subDays(44));
            $consumer = $this->subscribe($client, 'C1');
            $seller = $this->subscribe($client, 'V1');
            $perso = $this->subscribe($client, 'PERSO');

            $this->at($start->subDays(30));
            $this->topUp($client, $perso, 10000, true);

            $this->at($start->subDays(28));
            $this->destination($client, 'CASH');
            $first = $this->sale($client, $seller, '40', 'YEMPay-DEMO-0001', confirm: true);
            $batch = PayoutBatch::query()->where('payable_id', $first->id)->sole();
            app(ManualSettlementService::class)->record($this->admin, $batch, 'CASH', 'REÇU-DEMO-0001', $batch->remaining_amount, now(), 'Remis au siège');

            $this->at($start->subDays(20));
            $this->destination($client, 'TMONEY');

            $this->at($start->subDays(18));
            $this->sale($client, $seller, '30', 'YEMPay-DEMO-0002', confirm: true);

            $this->at($start->subDays(14));
            app(PeriodRoller::class)->run(now());

            $this->at($start->subDays(12));
            $merchant = $this->merchant($password);

            $this->at($start->subDays(8));
            $this->payMerchant($client, $consumer, $merchant['token'], '20', 'YEMPay-DEMO-M1', $merchant['user'], confirm: true);

            $this->at($start->subDays(3));
            $this->payMerchant($client, $consumer, $merchant['token'], '10', 'YEMPay-DEMO-M2', $merchant['user'], confirm: false);

            $this->at($start->subDays(2));
            $this->sale($client, $seller, '20', 'YEMPay-DEMO-0003', confirm: false);

            $this->at($start->subDay());
            $this->topUp($client, $perso, 5000, false);

            $this->at($start);
            $this->sale($client, $seller, '10', null, confirm: false, declare: false);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        $this->command->info("Données de démonstration créées pour {$email}.");
        $pernum = $client->pernumIdentities()->value('pernum');
        $this->command->table(['Compte', 'Identifiant', 'Mot de passe'], [
            ['Client (membre)', "{$email} ou Pernum {$pernum}", $existing !== null ? '(inchangé)' : $password],
            ['Marchand (membre)', (string) config('demo.merchant_email'), $password],
            ['Personnel (Superadmin) : back-office /backoffice', (string) config('demo.staff_email'), $password],
        ]);
    }

    /**
     * The demonstration history starts weeks ago, when the parameters did not exist yet: every parameter gets a
     * reference version dated at the start of the history (same value as today). Versions are only ever added.
     */
    private function backdateSettings(CarbonImmutable $from): void
    {
        $settings = app(SettingsService::class);

        foreach (Setting::query()->pluck('key') as $key) {
            if (SettingVersion::query()->where('setting_key', $key)->where('effective_from', '<=', $from)->exists()) {
                continue;
            }

            SettingVersion::query()->create([
                'setting_key' => $key,
                'value' => $settings->get($key),
                'effective_from' => $from,
                'created_by' => null,
                'reason' => 'Valeur de référence au début de l\'historique de démonstration',
                'created_at' => $from,
            ]);
            $settings->forget($key);
        }
    }

    private function at(CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    /**
     * The acting validator/accountant: an existing Superadmin, or a demonstration one.
     */
    private function demoStaff(string $password): User
    {
        $existing = User::query()->role(PermissionCatalog::SUPERADMIN)->where('status', UserStatus::Active)->first();

        if ($existing !== null) {
            return $existing;
        }

        $staff = User::query()->create([
            'kind' => UserKind::Staff,
            'first_name' => 'Superadmin',
            'last_name' => 'Démo',
            'email' => (string) config('demo.staff_email'),
            'phone' => '+22890000001',
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'password' => $password,
        ]);
        $staff->assignRole(PermissionCatalog::SUPERADMIN);

        return $staff;
    }

    /**
     * Publishes the plans used by the demonstration (the rest of the legacy grid stays in draft).
     */
    private function publishDemoPlans(): void
    {
        $catalog = app(CatalogManager::class);

        foreach (['C1', 'V1', 'PERSO', 'BRONZE'] as $code) {
            $plan = PackPlan::query()->where('code', $code)->firstOrFail();

            if ($plan->status === PackPlanStatus::Published) {
                continue;
            }

            $version = $plan->versions()->whereNull('published_at')->firstOrFail();
            $catalog->publishVersion($this->admin, $plan, $version);
            $catalog->transition($this->admin, $plan->refresh(), PackPlanStatus::Published, 'Publication pour la démonstration');
        }
    }

    /**
     * Registers a member, verifies the e-mail, and gets the cash membership payment declared and validated.
     *
     * @param  array<string, string>  $identity
     */
    private function activatedMember(string $email, string $password, array $identity): User
    {
        $registered = app(RegisterMember::class)->handle([
            ...$identity,
            'email' => $email,
            'password' => $password,
            'signed_place' => 'Lomé',
            'signed_on' => now()->toDateString(),
        ]);
        $member = $registered['user'];
        $member->forceFill(['status' => UserStatus::PendingPayment, 'email_verified_at' => now()])->save();

        return $this->payMembership($member);
    }

    /**
     * A member who already registered by themselves: the demonstration history is added to the account, which keeps
     * its password, e-mail, Pernum and phone. The account is dated at the start of the history so that the dates of
     * its operations make sense.
     */
    private function enrichedMember(User $member, CarbonImmutable $registeredAt): User
    {
        DB::table('users')->where('id', $member->id)->update(['created_at' => $registeredAt]);

        $member->forceFill([
            'status' => UserStatus::PendingPayment,
            'email_verified_at' => $member->email_verified_at ?? now(),
        ])->save();

        return $this->payMembership($member->refresh());
    }

    private function canEnrich(User $member): bool
    {
        return $member->kind === UserKind::Member
            && in_array($member->status, [UserStatus::PendingEmailVerification, UserStatus::PendingPayment], true)
            && ! UserPack::query()->where('user_id', $member->id)->exists();
    }

    private function payMembership(User $member): User
    {
        $this->approveIdentity($member);
        $payment = app(StartMembershipPayment::class)->handle($member, 'CASH');
        app(ManualPaymentDesk::class)->declare($member, $payment['attempt'], 'Remis au siège');
        app(ManualPaymentDesk::class)->approve($this->admin, $payment['attempt']->refresh(), $payment['attempt']->amount, 'REÇU-ADH-'.$member->member_number, null);

        return $member->refresh();
    }

    /**
     * Demonstration accounts stand for members whose identity the CECT already checked, the condition to pay the
     * membership (an approved KYC case, Didit evidence included when Didit is the provider) and to subscribe a pack.
     */
    private function approveIdentity(User $member): void
    {
        if (! $member->hasVerifiedIdentity()) {
            $member->forceFill(['identity_verified_at' => now()])->save();
        }

        if (KycEligibility::approved($member)) {
            return;
        }

        MemberKycCase::query()->updateOrCreate(['user_id' => $member->id], [
            'status' => 'approved',
            'verification_mode' => 'didit',
            'privacy_notice_accepted' => true,
            'submitted_at' => now(),
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'review_reason' => 'Compte de démonstration',
            'provider_status' => 'Approved',
            'provider_checks' => ['complete' => true, 'business_verified' => true],
            'provider_environment' => config('kyc.didit.environment'),
            'provider_verified_at' => now(),
            'provider_reviewed_at' => now(),
        ]);
    }

    private function subscribe(User $member, string $code): UserPack
    {
        $plan = PackPlan::query()->where('code', $code)->firstOrFail();
        $this->approveIdentity($member);
        $result = app(SubscribeToPack::class)->handle($member, $plan, 'CASH');

        if ($result['attempt'] !== null) {
            app(ManualPaymentDesk::class)->declare($member, $result['attempt'], 'Remis au siège');
            app(ManualPaymentDesk::class)->approve($this->admin, $result['attempt']->refresh(), $result['attempt']->amount, "REÇU-{$code}-".$member->member_number, null);
        }

        return $this->makeSellableNow($result['user_pack']->refresh());
    }

    /**
     * A Vendeur pack bought today only releases its first allowance after a month. The demonstration needs a history,
     * so the periods are moved back by that waiting time and the allowance due is credited.
     */
    private function makeSellableNow(UserPack $pack): UserPack
    {
        $first = PackPeriod::query()->where('user_pack_id', $pack->id)->orderBy('number')->first();

        if ($first === null || $first->starts_at->lessThanOrEqualTo(now())) {
            return $pack;
        }

        $shift = (int) ceil(now()->diffInSeconds($first->starts_at, false));

        PackPeriod::query()->where('user_pack_id', $pack->id)->get()->each(fn (PackPeriod $period) => $period->forceFill([
            'starts_at' => $period->starts_at->subSeconds($shift),
            'ends_at' => $period->ends_at->subSeconds($shift),
        ])->save());

        $pack->forceFill(['expires_at' => $pack->expires_at?->subSeconds($shift)])->save();
        app(PeriodRoller::class)->creditDuePeriods($pack->refresh(), now());

        return $pack->refresh();
    }

    private function topUp(User $member, UserPack $pack, int $amount, bool $validate): void
    {
        $result = app(RequestPackTopup::class)->handle($member, $pack, $amount, 'CASH');
        app(ManualPaymentDesk::class)->declare($member, $result['attempt'], 'Dépôt du '.now()->format('d/m'));

        if ($validate) {
            app(ManualPaymentDesk::class)->approve($this->admin, $result['attempt']->refresh(), $result['attempt']->amount, 'REÇU-PERSO-'.now()->format('md'), null);
        }
    }

    private function destination(User $member, string $method): void
    {
        $mobile = $method !== 'CASH';

        PayoutDestination::query()->updateOrCreate(['user_id' => $member->id], [
            'method' => $method,
            'channel' => $mobile ? 'mobile_money' : 'manual',
            'phone' => $mobile ? ($member->phone ?? '+22890000055') : null,
            'bank_name' => null,
            'account_holder' => null,
            'account_number' => null,
        ]);
    }

    private function sale(User $member, UserPack $pack, string $quantity, ?string $reference, bool $confirm, bool $declare = true): ExchangeRequest
    {
        $exchange = app(SellerExchangeService::class)->create($member, $pack->load('version'), $quantity);

        if ($declare) {
            $exchange = app(SellerExchangeService::class)->declareSent($member, $exchange, $reference);
        }

        if ($confirm) {
            $exchange = app(ValidationDesk::class)->confirm($this->admin, $exchange, $quantity, $reference, null);
        }

        return $exchange;
    }

    /**
     * A merchant (legal person) with an active Pack Marchand, a QR code and a public directory entry.
     *
     * @return array{user: User, token: string}
     */
    private function merchant(string $password): array
    {
        $existing = User::query()->where('email', (string) config('demo.merchant_email'))->first();
        $qr = $existing === null ? null : MerchantQrCode::query()->where('user_id', $existing->id)->where('status', MerchantQrCode::ACTIVE)->first();

        if ($existing !== null && $qr !== null) {
            return ['user' => $existing, 'token' => $qr->token];
        }

        $registered = app(RegisterMember::class)->handle([
            'person_type' => 'legal', 'first_name' => 'Awa', 'last_name' => 'Koffi', 'email' => (string) config('demo.merchant_email'), 'phone' => '+22890000077',
            'pernum' => '1009000077', 'city' => 'Lomé', 'password' => $password, 'signed_place' => 'Lomé', 'signed_on' => now()->toDateString(),
            'legal_name' => 'Pharmacie Centrale (démo)', 'legal_form' => 'SARL', 'rccm' => 'TG-LOM-2020-B-0001', 'nif' => '1000000001',
        ]);
        $merchant = $registered['user'];
        $merchant->forceFill(['status' => UserStatus::PendingPayment, 'email_verified_at' => now()])->save();
        $this->approveIdentity($merchant);

        $payment = app(StartMembershipPayment::class)->handle($merchant, 'CASH');
        app(ManualPaymentDesk::class)->declare($merchant, $payment['attempt'], 'Virement du jour');
        app(ManualPaymentDesk::class)->approve($this->admin, $payment['attempt']->refresh(), $payment['attempt']->amount, 'REÇU-ADH-'.$merchant->member_number, null);

        $pack = $this->subscribe($merchant->refresh(), 'BRONZE');
        $qr = app(MerchantQrService::class)->issue($merchant, $pack, 'Boutique principale');

        MerchantDirectoryProfile::query()->updateOrCreate(['user_id' => $merchant->id], [
            'listed' => true, 'display_name' => 'Pharmacie Centrale (démo)', 'activity' => 'Pharmacie', 'city' => 'Lomé',
            'address' => 'Boulevard du 13 Janvier', 'public_phone' => '+22890000077', 'description' => 'Pharmacie acceptant les YEM — données de démonstration.', 'listed_at' => now(),
        ]);

        return ['user' => $merchant->refresh(), 'token' => $qr->token];
    }

    private function payMerchant(User $payer, UserPack $pack, string $token, string $quantity, string $reference, User $merchant, bool $confirm): void
    {
        $payments = app(MerchantPaymentService::class);
        $payment = $payments->create($payer, $pack, $token, $quantity);
        $payment = $payments->declareSent($payer, $payment, $reference);

        if ($confirm) {
            $payments->confirmByMerchant($merchant, $payment);
        }
    }
}
