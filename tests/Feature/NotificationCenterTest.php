<?php

use App\Domain\Notification\Models\MemberNotification;
use App\Models\User;

it('returns only notifications owned by the authenticated user', function () {
    $member = User::factory()->create();
    $other = User::factory()->create();

    MemberNotification::query()->create([
        'user_id' => $member->id,
        'event' => 'pack.activated',
        'title' => 'Votre pack est actif',
        'message' => 'Pack activé.',
    ]);

    MemberNotification::query()->create([
        'user_id' => $other->id,
        'event' => 'exchange.yem_received',
        'title' => 'YEM reçus',
        'message' => 'Notification privée.',
    ]);

    $this->withToken(tokenFor($member))
        ->getJson('/api/v1/me/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Votre pack est actif')
        ->assertJsonPath('meta.unread', 1);
});

it('does not let a member mark another members notification as read', function () {
    $member = User::factory()->create();
    $other = User::factory()->create();

    $notification = MemberNotification::query()->create([
        'user_id' => $other->id,
        'event' => 'membership.activated',
        'title' => 'Adhésion active',
        'message' => 'Privé.',
    ]);

    $this->withToken(tokenFor($member))
        ->patchJson("/api/v1/me/notifications/{$notification->id}/read")
        ->assertNotFound();

    expect($notification->refresh()->read_at)->toBeNull();
});

it('marks the members own notifications as read', function () {
    $member = User::factory()->create();

    MemberNotification::query()->create([
        'user_id' => $member->id,
        'event' => 'membership.activated',
        'title' => 'Adhésion active',
        'message' => 'Bienvenue.',
    ]);
    MemberNotification::query()->create([
        'user_id' => $member->id,
        'event' => 'pack.activated',
        'title' => 'Pack actif',
        'message' => 'Votre pack est actif.',
    ]);

    $this->withToken(tokenFor($member))
        ->patchJson('/api/v1/me/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.updated', 2);

    expect(MemberNotification::query()->where('user_id', $member->id)->whereNull('read_at')->count())->toBe(0);
});
