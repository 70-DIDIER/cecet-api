<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Reporting\Models\ExportSnapshot;
use App\Domain\Reporting\Snapshots\SnapshotGenerator;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    Storage::fake('local');
    $this->admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
});

/**
 * A membership paid in cash and validated: a major business mutation.
 */
function businessActivity(): User
{
    static $counter = 0;
    $member = User::factory()->status(UserStatus::PendingPayment)->create(['first_name' => 'Yendoukoa', 'last_name' => 'NAKORE']);
    $member->pernumIdentities()->create(['pernum' => (string) (1001043953 + $counter++), 'kind' => 'personal', 'status' => 'declared']);

    $attemptId = test()->withToken(tokenFor($member))
        ->postJson('/api/v1/me/membership/payments', ['method' => 'CASH'], ['Idempotency-Key' => (string) Str::ulid()])
        ->json('data.id');
    test()->withToken(tokenFor(User::factory()->staff(PermissionCatalog::VALIDATOR)->create(), withStepUp: true))
        ->postJson("/api/v1/validations/payments/{$attemptId}/approve", ['received_amount' => 25000, 'reference' => 'Reçu 1'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    return $member;
}

/**
 * @return array<string, string> file name => content
 */
function snapshotArchive(ExportSnapshot $snapshot): array
{
    $path = tempnam(sys_get_temp_dir(), 'snap');
    file_put_contents($path, Storage::disk('local')->get($snapshot->path));
    $zip = new ZipArchive;
    $zip->open($path);
    $files = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);
        $files[$name] = (string) $zip->getFromIndex($index);
    }

    $zip->close();
    unlink($path);

    return $files;
}

it('generates a snapshot only after business operations, with a verifiable manifest', function () {
    $this->artisan('snapshots:generate')->expectsOutputToContain('pas de snapshot')->assertSuccessful();
    expect(ExportSnapshot::query()->count())->toBe(0);

    $member = businessActivity();

    $this->artisan('snapshots:generate')->expectsOutputToContain('Snapshot v')->assertSuccessful();

    $snapshot = ExportSnapshot::query()->sole();
    expect($snapshot->status)->toBe('ready')
        ->and($snapshot->trigger)->toBe('automatic')
        ->and($snapshot->sha256)->toBe(hash('sha256', Storage::disk('local')->get($snapshot->path)));

    $files = snapshotArchive($snapshot);
    $manifest = json_decode($files['manifest.json'], true);

    expect(array_keys($files))->toContain('membres.csv', 'packs.csv', 'journal_quota.csv', 'echanges.csv', 'paiements.csv', 'paiements_marchands.csv', 'payouts.csv', 'audit.csv')
        ->and($manifest['snapshot_id'])->toBe($snapshot->id)
        ->and($manifest['schema_version'])->toBe(1)
        ->and($manifest['audit_position'])->toBe($snapshot->audit_position)
        ->and($manifest['files']['membres.csv']['rows'])->toBe(1);

    foreach ($manifest['files'] as $name => $meta) {
        expect(hash('sha256', $files[$name]))->toBe($meta['sha256']);
    }

    expect($files['membres.csv'])->toContain($member->pernumIdentities()->value('pernum'))->toContain('Yendoukoa')
        ->and($files['paiements.csv'])->toContain('CASH')
        ->and($files['audit.csv'])->toContain('memberships.activated')->not->toContain('ip_address');

    // Nothing new: no second snapshot.
    $this->artisan('snapshots:generate')->expectsOutputToContain('pas de snapshot');
    expect(AuditEvent::query()->where('action', 'snapshots.generated')->count())->toBe(1);
});

it('groups close operations and regenerates after the minimum interval', function () {
    businessActivity();
    $this->artisan('snapshots:generate');

    businessActivity();
    expect(app(SnapshotGenerator::class)->isDue())->toBeFalse();

    $this->travel(16)->minutes();
    expect(app(SnapshotGenerator::class)->isDue())->toBeTrue();

    $this->artisan('snapshots:generate')->assertSuccessful();
    expect(ExportSnapshot::query()->pluck('version')->all())->toHaveCount(2);
});

it('keeps the history but deletes archives beyond the retention count', function () {
    app(SettingsService::class)->set('reporting.snapshot_retention_count', 2, 'Rétention courte');

    foreach (range(1, 3) as $ignored) {
        $this->artisan('snapshots:generate --force')->assertSuccessful();
    }

    $oldest = ExportSnapshot::query()->orderBy('version')->first();
    expect($oldest->status)->toBe('pruned')
        ->and($oldest->sha256)->not->toBeNull()
        ->and(Storage::disk('local')->exists($oldest->path))->toBeFalse()
        ->and(ExportSnapshot::query()->where('status', 'ready')->count())->toBe(2);
});

it('lets the back-office ask for a snapshot and download the latest one, with a step-up, audited', function () {
    businessActivity();

    $this->withToken(tokenFor($this->admin))->postJson('/api/v1/admin/snapshots')->assertStatus(202);
    $snapshot = ExportSnapshot::query()->sole();
    expect($snapshot->trigger)->toBe('manual')->and($snapshot->requested_by)->toBe($this->admin->id);

    $list = $this->withToken(tokenFor($this->admin))->getJson('/api/v1/admin/snapshots')
        ->assertOk()
        ->assertJsonPath('meta.latest_ready', $snapshot->id);
    expect($list->json('data.0.files')['membres.csv']['rows'])->toBe(1);

    $this->withToken(tokenFor($this->admin))->get('/api/v1/admin/snapshots/latest/download')->assertForbidden();

    $this->withToken(tokenFor($this->admin, withStepUp: true))->get('/api/v1/admin/snapshots/latest/download')
        ->assertOk()
        ->assertHeader('X-Content-SHA256', $snapshot->sha256);

    expect(AuditEvent::query()->where('action', 'reports.snapshot_downloaded')->sole()->actor_id)->toBe($this->admin->id);
});

it('reserves snapshots to staff allowed to export', function () {
    $validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();

    $this->withToken(tokenFor($validator))->getJson('/api/v1/admin/snapshots')->assertForbidden();
    $this->withToken(tokenFor($validator, withStepUp: true))->get('/api/v1/admin/snapshots/latest/download')->assertForbidden();
});
