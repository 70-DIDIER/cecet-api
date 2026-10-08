<?php

use App\Domain\Shared\Http\Middleware\EnsureIdempotency;
use App\Domain\Shared\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->executions = 0;

    Route::middleware(['api', 'idempotent'])->prefix('api/v1/_test')->group(function () {
        Route::post('operations', function (Request $request) {
            $this->executions++;

            return response()->json(['operation' => $this->executions, 'amount' => $request->integer('amount')], 201);
        });

        Route::post('failing', function () {
            $this->executions++;

            abort(503);
        });
    });

    Route::middleware(['api', 'auth:sanctum', 'idempotent'])
        ->post('api/v1/_test/user-operations', function () {
            $this->executions++;

            return response()->json(['operation' => $this->executions], 201);
        });
});

it('requires an idempotency key', function () {
    $this->postJson('/api/v1/_test/operations', ['amount' => 100])
        ->assertStatus(400)
        ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');

    expect($this->executions)->toBe(0);
});

it('replays the stored response without executing the operation twice', function () {
    $headers = [EnsureIdempotency::HEADER => 'op-key-00000001'];

    $first = $this->postJson('/api/v1/_test/operations', ['amount' => 100], $headers);
    $second = $this->postJson('/api/v1/_test/operations', ['amount' => 100], $headers);

    $first->assertCreated()->assertHeaderMissing(EnsureIdempotency::REPLAY_HEADER);
    $second->assertCreated()
        ->assertHeader(EnsureIdempotency::REPLAY_HEADER, 'true')
        ->assertExactJson($first->json());

    expect($this->executions)->toBe(1);
});

it('rejects a key reused with a different payload', function () {
    $headers = [EnsureIdempotency::HEADER => 'op-key-00000002'];

    $this->postJson('/api/v1/_test/operations', ['amount' => 100], $headers)->assertCreated();

    $this->postJson('/api/v1/_test/operations', ['amount' => 999], $headers)
        ->assertStatus(422)
        ->assertJsonPath('code', 'IDEMPOTENCY_KEY_MISMATCH');

    expect($this->executions)->toBe(1);
});

it('rejects a duplicate while the first request is still processing', function () {
    IdempotencyKey::query()->create([
        'scope' => 'guest:127.0.0.1',
        'key' => 'op-key-00000003',
        'request_fingerprint' => hash('sha256', 'POST|api/v1/_test/operations|{"amount":100}'),
        'status' => IdempotencyKey::STATUS_PROCESSING,
        'expires_at' => now()->addDay(),
    ]);

    $this->postJson('/api/v1/_test/operations', ['amount' => 100], [EnsureIdempotency::HEADER => 'op-key-00000003'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'IDEMPOTENCY_REQUEST_IN_PROGRESS');

    expect($this->executions)->toBe(0);
});

it('releases the key after a server error so the client can retry', function () {
    $headers = [EnsureIdempotency::HEADER => 'op-key-00000004'];

    $this->postJson('/api/v1/_test/failing', [], $headers)->assertStatus(503);
    $this->postJson('/api/v1/_test/failing', [], $headers)->assertStatus(503);

    expect($this->executions)->toBe(2)
        ->and(IdempotencyKey::query()->where('key', 'op-key-00000004')->exists())->toBeFalse();
});

it('scopes keys per authenticated user', function () {
    $headers = [EnsureIdempotency::HEADER => 'shared-key-0001'];

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/v1/_test/user-operations', [], $headers)
        ->assertJsonPath('operation', 1);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/v1/_test/user-operations', [], $headers)
        ->assertJsonPath('operation', 2)
        ->assertHeaderMissing(EnsureIdempotency::REPLAY_HEADER);
});

it('lets the client fix a rejected request and retry it with the same key', function () {
    Route::middleware(['api', 'idempotent'])->post('api/v1/_test/validated', function (Request $request) {
        $request->validate(['amount' => ['required', 'integer']]);

        return response()->json(['ok' => true], 201);
    });
    $headers = [EnsureIdempotency::HEADER => 'op-key-00000005'];

    $this->postJson('/api/v1/_test/validated', [], $headers)->assertStatus(422);
    $this->postJson('/api/v1/_test/validated', ['amount' => 5], $headers)->assertCreated();
    $this->postJson('/api/v1/_test/validated', ['amount' => 5], $headers)->assertCreated()->assertHeader(EnsureIdempotency::REPLAY_HEADER, 'true');
});
