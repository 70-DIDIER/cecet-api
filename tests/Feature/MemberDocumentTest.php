<?php

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Membership\Enums\ActivationMode;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Models\Membership;
use App\Models\User;

it('lists private member documents only for the authenticated active member', function () {
    $member = User::factory()->status(UserStatus::Active)->create();

    Membership::query()->create([
        'user_id' => $member->id,
        'status' => MembershipStatus::Active,
        'fee_amount' => 25000,
        'fee_currency' => 'XOF',
        'fee_setting_key' => 'membership.fee.natural_person',
        'paid_at' => now(),
        'activated_at' => now(),
        'activation_mode' => ActivationMode::Payment,
    ]);

    $this->withToken(tokenFor($member))
        ->getJson('/api/v1/me/documents')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.type', 'membership-receipt');
});

it('downloads a members own document and audits the operation', function () {
    $member = User::factory()->status(UserStatus::Active)->create();

    Membership::query()->create([
        'user_id' => $member->id,
        'status' => MembershipStatus::Active,
        'fee_amount' => 25000,
        'fee_currency' => 'XOF',
        'fee_setting_key' => 'membership.fee.natural_person',
        'paid_at' => now(),
        'activated_at' => now(),
        'activation_mode' => ActivationMode::Payment,
    ]);

    $this->withToken(tokenFor($member))
        ->get('/api/v1/me/documents/membership-receipt.pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->assertDatabaseHas('audit_events', [
        'actor_id' => $member->id,
        'action' => 'member_documents.downloaded',
    ]);
});

it('does not expose membership documents before activation', function () {
    $member = User::factory()->status(UserStatus::PendingPayment)->create();

    Membership::query()->create([
        'user_id' => $member->id,
        'status' => MembershipStatus::PendingPayment,
        'fee_amount' => 25000,
        'fee_currency' => 'XOF',
        'fee_setting_key' => 'membership.fee.natural_person',
    ]);

    $this->withToken(tokenFor($member))
        ->getJson('/api/v1/me/documents')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->withToken(tokenFor($member))
        ->get('/api/v1/me/documents/membership-receipt.pdf')
        ->assertNotFound();
});
