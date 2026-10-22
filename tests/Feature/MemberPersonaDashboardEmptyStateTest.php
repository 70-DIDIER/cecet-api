<?php

use App\Models\User;

beforeEach(function (): void {
    seedCatalogs();
});

it('shows a truthful empty workspace for natural and legal members without inventing packs or spendable YEM', function (): void {
    $natural = User::factory()->create();
    $legal = User::factory()->legalPerson()->create();

    foreach ([
        [$natural, 'natural'],
        [$legal, 'legal'],
    ] as [$member, $personType]) {
        $this->withToken(tokenFor($member))
            ->getJson('/api/v1/me/dashboard')
            ->assertOk()
            ->assertJsonPath('data.profile.person_type', $personType)
            ->assertJsonPath('data.active_packs', [])
            ->assertJsonPath('data.operations', [])
            ->assertJsonPath('data.yem.balance', '0.000000')
            ->assertJsonPath('data.yem.reserved', '0.000000')
            ->assertJsonPath('data.yem.total_credited', '0.000000')
            ->assertJsonPath('data.yem.total_spent', '0.000000');
    }
});
