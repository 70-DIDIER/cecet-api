<?php

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Http\Middleware\AttachCorrelationId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/_test')->group(function () {
        Route::post('validation', fn (Request $request) => $request->validate(['amount' => ['required', 'integer']]));
        Route::get('business', fn () => throw new ApiException('QUOTA_EXCEEDED', 'Quota insuffisant.', 422, ['available' => 10]));
        Route::get('protected', fn () => 'secret')->middleware('auth:sanctum');
        Route::get('crash', fn () => throw new RuntimeException('database password leaked'));
    });
});

it('renders validation errors with the normalized envelope', function () {
    $response = $this->postJson('/api/v1/_test/validation', []);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('correlation_id', $response->headers->get(AttachCorrelationId::HEADER))
        ->assertJsonStructure(['message', 'details' => ['fields' => ['amount']]]);
});

it('renders business exceptions with their code, status and details', function () {
    $this->getJson('/api/v1/_test/business')
        ->assertStatus(422)
        ->assertJsonPath('code', 'QUOTA_EXCEEDED')
        ->assertJsonPath('message', 'Quota insuffisant.')
        ->assertJsonPath('details.available', 10);
});

it('renders unauthenticated access as 401', function () {
    $this->getJson('/api/v1/_test/protected')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('renders unknown api routes as 404 with a correlation id', function () {
    $response = $this->getJson('/api/v1/does-not-exist');

    $response->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND')
        ->assertJsonPath('correlation_id', $response->headers->get(AttachCorrelationId::HEADER));
});

it('hides internal error messages when debug is disabled', function () {
    config(['app.debug' => false]);

    $this->getJson('/api/v1/_test/crash')
        ->assertStatus(500)
        ->assertJsonPath('code', 'INTERNAL_ERROR')
        ->assertJsonMissing(['message' => 'database password leaked']);
});
