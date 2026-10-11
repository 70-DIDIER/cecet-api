<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Enums\PackFamily;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();

    $this->merchant = User::factory()->legalPerson()->create();
    $this->merchantPack = subscribeAndPay($this->merchant, makePlan(PackFamily::Marchand, 'BRONZE', periodicTerms(['eligible_person_types' => ['legal']])));
});

function profile(array $overrides = []): array
{
    return ['listed' => true, 'display_name' => 'Pharmacie Centrale', 'activity' => 'Pharmacie', 'city' => 'Lomé', 'public_phone' => '+22890000001', ...$overrides];
}

it('lists only merchants who chose to appear, with what they chose to publish', function () {
    $this->getJson('/api/v1/merchants')->assertOk()->assertJsonCount(0, 'data');

    $this->withToken(tokenFor($this->merchant))->putJson('/api/v1/merchant/directory-profile', profile(['listed' => false]))->assertOk()->assertJsonPath('data.listed', false);
    $this->getJson('/api/v1/merchants')->assertJsonCount(0, 'data');

    $this->withToken(tokenFor($this->merchant))->putJson('/api/v1/merchant/directory-profile', profile())->assertOk();

    $this->getJson('/api/v1/merchants?city=lomé&search=pharma')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Pharmacie Centrale')
        ->assertJsonPath('data.0.phone', '+22890000001')
        ->assertJsonMissingPath('data.0.email');

    $this->getJson('/api/v1/merchants?city=Kara')->assertJsonCount(0, 'data');
});

it('removes a merchant whose pack is no longer active or whose account is suspended', function () {
    $this->withToken(tokenFor($this->merchant))->putJson('/api/v1/merchant/directory-profile', profile())->assertOk();

    $this->merchantPack->forceFill(['status' => 'expired'])->save();
    $this->getJson('/api/v1/merchants')->assertJsonCount(0, 'data');

    $this->merchantPack->forceFill(['status' => 'active'])->save();
    $this->merchant->forceFill(['status' => 'suspended'])->save();
    $this->getJson('/api/v1/merchants')->assertJsonCount(0, 'data');
});

it('reserves the directory profile to merchants', function () {
    $this->withToken(tokenFor(User::factory()->create()))->putJson('/api/v1/merchant/directory-profile', profile())
        ->assertForbidden()
        ->assertJsonPath('code', 'MERCHANT_PACK_REQUIRED');
});

it('lets an administrator moderate the public merchant directory with step-up and an audit trail', function () {
    $this->withToken(tokenFor($this->merchant))->putJson('/api/v1/merchant/directory-profile', profile())->assertOk();

    $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();

    $this->withToken(tokenFor($admin))
        ->getJson('/api/v1/admin/merchant-directory')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.display_name', 'Pharmacie Centrale');

    $payload = [
        ...profile(['listed' => false, 'display_name' => 'Pharmacie Centrale — vérifiée']),
        'reason' => 'Correction de la fiche publique',
    ];

    $this->withToken(tokenFor($admin))
        ->putJson("/api/v1/admin/merchant-directory/{$this->merchant->id}", $payload)
        ->assertForbidden()
        ->assertJsonPath('code', 'STEP_UP_REQUIRED');

    $this->withToken(tokenFor($admin, withStepUp: true))
        ->putJson("/api/v1/admin/merchant-directory/{$this->merchant->id}", $payload)
        ->assertOk()
        ->assertJsonPath('data.listed', false)
        ->assertJsonPath('data.display_name', 'Pharmacie Centrale — vérifiée');

    $this->getJson('/api/v1/merchants')->assertOk()->assertJsonCount(0, 'data');

    expect(AuditEvent::query()->where('action', 'merchant_directory.admin_updated')->where('actor_id', $admin->id)->exists())->toBeTrue();
});
