<?php

use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;

/**
 * A token created before suspension may remain physically present in Sanctum.
 * The API must independently authorize account status on EVERY request.
 */
it('enforces all six membership statuses even when the bearer token already exists', function (UserStatus $status, int $expected) {
    $user = User::factory()->status($status)->create();
    $this->withToken(tokenFor($user))->getJson('/api/v1/me')->assertStatus($expected);
})->with([
    'active member' => [UserStatus::Active, 200],
    'awaiting payment can view own account to finish joining' => [UserStatus::PendingPayment, 200],
    'unverified e-mail blocked' => [UserStatus::PendingEmailVerification, 401],
    'activation pending blocked' => [UserStatus::PendingActivation, 401],
    'suspended blocked' => [UserStatus::Suspended, 401],
    'closed blocked' => [UserStatus::Closed, 401],
]);

it('prevents suspended and closed internal staff from reusing preexisting API tokens', function (UserStatus $status) {
    $staff = User::factory()->staff('comptabilite')->status($status)->create();
    $this->withToken(tokenFor($staff))->getJson('/api/v1/me')->assertUnauthorized();
})->with([
    'suspended staff' => [UserStatus::Suspended],
    'closed staff' => [UserStatus::Closed],
    'staff pending activation' => [UserStatus::PendingActivation],
    'staff pending payment not allowed' => [UserStatus::PendingPayment],
]);

it('revokes access immediately after a formerly active member becomes suspended or closed', function () {
    $member = User::factory()->create();
    $token = tokenFor($member);

    $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    $member->forceFill(['status' => UserStatus::Suspended])->save();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    $member->forceFill(['status' => UserStatus::Closed])->save();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});
