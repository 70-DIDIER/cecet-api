<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Reporting\Models\ExportSnapshot;
use App\Domain\Reporting\Models\ImportJob;
use App\Domain\Reporting\Snapshots\SnapshotGenerator;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    Storage::fake('local');

    $this->alice = User::factory()->create(['phone' => '+22890000001', 'city' => 'Lomé']);
    $this->bob = User::factory()->create(['phone' => '+22890000002', 'city' => 'Kara']);
    $this->snapshot = app(SnapshotGenerator::class)->generate('manual');

    $this->uploader = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    $this->approver = User::factory()->staff(PermissionCatalog::ADMIN)->create();
});

/**
 * The member file of the snapshot as rows, to be edited like in a spreadsheet.
 *
 * @return array{0: list<string>, 1: array<string, array<string, string>>}
 */
function snapshotMembers(ExportSnapshot $snapshot): array
{
    $path = tempnam(sys_get_temp_dir(), 'snap');
    file_put_contents($path, Storage::disk('local')->get($snapshot->path));
    $zip = new ZipArchive;
    $zip->open($path);
    $content = substr((string) $zip->getFromName('membres.csv'), 3);
    $zip->close();
    unlink($path);

    $lines = array_map(fn (string $line) => str_getcsv($line, ';', '"', ''), array_filter(explode("\n", $content)));
    $header = array_shift($lines);
    $rows = [];

    foreach ($lines as $values) {
        $row = array_combine($header, $values);
        $rows[$row['user_id']] = $row;
    }

    return [$header, $rows];
}

/**
 * @param  list<string>  $header
 * @param  array<string, array<string, string>>  $rows
 */
function membersCsv(array $header, array $rows): UploadedFile
{
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, "\u{FEFF}");
    fputcsv($stream, $header, ';', escape: '');

    foreach ($rows as $row) {
        fputcsv($stream, array_values($row), ';', escape: '');
    }

    rewind($stream);

    return UploadedFile::fake()->createWithContent('membres-corrige.csv', (string) stream_get_contents($stream));
}

function stageImport(User $uploader, UploadedFile $file, ExportSnapshot $snapshot): TestResponse
{
    return test()->withToken(tokenFor($uploader, withStepUp: true))
        ->post('/api/v1/admin/imports', ['file' => $file, 'base_snapshot_id' => $snapshot->id], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::ulid()]);
}

function approveImport(User $approver, string $id): TestResponse
{
    return test()->withToken(tokenFor($approver, withStepUp: true))
        ->postJson("/api/v1/admin/imports/{$id}/approve", ['comment' => 'Vérifié avec le registre papier'], ['Idempotency-Key' => (string) Str::ulid()]);
}

it('stages the corrected file without touching anything, then applies it after a second approval', function () {
    [$header, $rows] = snapshotMembers($this->snapshot);
    $rows[$this->alice->id]['phone'] = '+22891111111';
    $rows[$this->alice->id]['city'] = 'Tsévié';

    $job = stageImport($this->uploader, membersCsv($header, $rows), $this->snapshot)
        ->assertCreated()
        ->assertJsonPath('data.status', 'staged')
        ->assertJsonPath('data.summary.change', 1)
        ->assertJsonPath('data.summary.unchanged', 1)
        ->assertJsonPath('data.can_be_applied', true)
        ->assertJsonPath('data.rows.0.changes.phone.base', '+22890000001')
        ->assertJsonPath('data.rows.0.changes.phone.imported', '+22891111111')
        ->json('data');

    expect($this->alice->refresh()->phone)->toBe('+22890000001');

    approveImport($this->uploader, $job['id'])->assertForbidden()->assertJsonPath('code', 'IMPORT_SAME_APPROVER');

    approveImport($this->approver, $job['id'])->assertOk()->assertJsonPath('data.status', 'applied');

    expect($this->alice->refresh()->phone)->toBe('+22891111111')
        ->and($this->alice->city)->toBe('Tsévié')
        ->and($this->bob->refresh()->phone)->toBe('+22890000002');

    $audit = AuditEvent::query()->where('action', 'imports.member_updated')->sole();
    expect($audit->before)->toEqualCanonicalizing(['phone' => '+22890000001', 'city' => 'Lomé'])
        ->and($audit->actor_id)->toBe($this->approver->id);

    // The same file cannot be applied twice.
    stageImport($this->uploader, membersCsv($header, $rows), $this->snapshot)->assertStatus(409)->assertJsonPath('code', 'IMPORT_ALREADY_APPLIED');
});

it('flags a value changed both in the file and on the platform as a conflict, never overwritten', function () {
    [$header, $rows] = snapshotMembers($this->snapshot);
    $rows[$this->alice->id]['phone'] = '+22891111111';
    $this->alice->forceFill(['phone' => '+22892222222'])->save();

    $job = stageImport($this->uploader, membersCsv($header, $rows), $this->snapshot)
        ->assertCreated()
        ->assertJsonPath('data.summary.conflict', 1)
        ->assertJsonPath('data.can_be_applied', false)
        ->assertJsonPath('data.rows.0.changes.phone.current', '+22892222222')
        ->json('data');

    approveImport($this->approver, $job['id'])->assertStatus(422)->assertJsonPath('code', 'IMPORT_NOT_APPLICABLE');
    expect($this->alice->refresh()->phone)->toBe('+22892222222');
});

it('refuses changes to columns that are never importable, and invalid values', function () {
    [$header, $rows] = snapshotMembers($this->snapshot);
    $rows[$this->alice->id]['email'] = 'pirate@example.com';
    $rows[$this->alice->id]['pernum'] = '9999999999';
    $rows[$this->bob->id]['phone'] = '92104097';

    $response = stageImport($this->uploader, membersCsv($header, $rows), $this->snapshot)
        ->assertCreated()
        ->assertJsonPath('data.summary.error', 2);

    $errors = collect($response->json('data.rows'))->pluck('errors')->flatten()->implode(' | ');
    expect($errors)->toContain('« email » ne peut pas')->toContain('« pernum » ne peut pas')->toContain('Téléphone invalide');
});

it('aborts everything when the data changed between staging and approval', function () {
    [$header, $rows] = snapshotMembers($this->snapshot);
    $rows[$this->alice->id]['city'] = 'Tsévié';
    $rows[$this->bob->id]['city'] = 'Dapaong';

    $job = stageImport($this->uploader, membersCsv($header, $rows), $this->snapshot)->assertJsonPath('data.summary.change', 2)->json('data');
    $this->bob->forceFill(['city' => 'Sokodé'])->save();

    approveImport($this->approver, $job['id'])->assertStatus(409)->assertJsonPath('code', 'IMPORT_STALE_DATA');

    expect($this->alice->refresh()->city)->toBe('Lomé')
        ->and(ImportJob::query()->findOrFail($job['id'])->status)->toBe('staged');
});

it('checks the schema, the identity of each line and the base snapshot', function () {
    [$header, $rows] = snapshotMembers($this->snapshot);

    stageImport($this->uploader, membersCsv(array_slice($header, 0, 5), array_map(fn ($row) => array_slice($row, 0, 5), $rows)), $this->snapshot)
        ->assertStatus(422)
        ->assertJsonPath('code', 'IMPORT_SCHEMA_MISMATCH');

    $rows[$this->alice->id]['member_number'] = 'CECT-FAUX';
    $rows['01ZZZZZZZZZZZZZZZZZZZZZZZZ'] = [...$rows[$this->bob->id], 'user_id' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ'];

    $response = stageImport($this->uploader, membersCsv($header, $rows), $this->snapshot)->assertCreated()->assertJsonPath('data.summary.error', 2);
    expect(collect($response->json('data.rows'))->pluck('errors')->flatten()->implode(' | '))->toContain('numéro de membre')->toContain('inconnu');

    // An altered base archive is refused.
    Storage::disk('local')->put($this->snapshot->path, 'altéré');
    stageImport($this->uploader, membersCsv($header, $rows), $this->snapshot)->assertStatus(422)->assertJsonPath('code', 'IMPORT_BASE_SNAPSHOT_UNAVAILABLE');
});

it('lets a staged import be canceled and reserves imports to authorised staff with a step-up', function () {
    [$header, $rows] = snapshotMembers($this->snapshot);
    $rows[$this->alice->id]['city'] = 'Aného';
    $id = stageImport($this->uploader, membersCsv($header, $rows), $this->snapshot)->json('data.id');

    $this->withToken(tokenFor($this->approver))->postJson("/api/v1/admin/imports/{$id}/cancel", ['reason' => 'Fichier incomplet'])->assertOk()->assertJsonPath('data.status', 'canceled');
    approveImport($this->approver, $id)->assertStatus(409);

    $validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
    stageImport($validator, membersCsv($header, $rows), $this->snapshot)->assertForbidden();

    $this->withToken(tokenFor($this->uploader))
        ->post('/api/v1/admin/imports', ['file' => membersCsv($header, $rows), 'base_snapshot_id' => $this->snapshot->id], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::ulid()])
        ->assertForbidden()
        ->assertJsonPath('code', 'STEP_UP_REQUIRED');
});
