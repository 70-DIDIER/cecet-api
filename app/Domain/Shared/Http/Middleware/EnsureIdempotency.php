<?php

namespace App\Domain\Shared\Http\Middleware;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Guarantees that a mutating request identified by an Idempotency-Key produces a single business result.
 *
 * The first request reserves the key; concurrent duplicates receive 409, completed duplicates receive
 * the stored response, and reusing a key with a different payload is rejected.
 * Failed requests (4xx and 5xx) release the key so the client can correct the request and retry with it.
 */
class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAY_HEADER = 'Idempotency-Replayed';

    private const KEY_FORMAT = '/^[\x21-\x7E]{8,255}$/';

    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->headers->get(self::HEADER, '');

        if (preg_match(self::KEY_FORMAT, $key) !== 1) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'L\'en-tête Idempotency-Key est obligatoire (8 à 255 caractères imprimables).',
                400,
            );
        }

        $scope = $this->scopeFor($request);
        $fingerprint = $this->fingerprint($request);

        $reserved = IdempotencyKey::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'scope' => $scope,
            'key' => $key,
            'request_fingerprint' => $fingerprint,
            'status' => IdempotencyKey::STATUS_PROCESSING,
            'expires_at' => now()->addHours(self::TTL_HOURS),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;

        if (! $reserved) {
            return $this->resolveExisting($scope, $key, $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            IdempotencyKey::query()->where('scope', $scope)->where('key', $key)->delete();

            throw $exception;
        }

        $this->storeOutcome($scope, $key, $response);

        return $response;
    }

    private function resolveExisting(string $scope, string $key, string $fingerprint): Response
    {
        $existing = IdempotencyKey::query()->where('scope', $scope)->where('key', $key)->firstOrFail();

        if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_MISMATCH',
                'Cette clé d\'idempotence a déjà été utilisée pour une requête différente.',
                422,
            );
        }

        if ($existing->status !== IdempotencyKey::STATUS_COMPLETED) {
            throw new ApiException(
                'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                'Une requête identique est déjà en cours de traitement.',
                409,
            );
        }

        return new IlluminateResponse((string) $existing->response_body, (int) $existing->response_status, array_filter([
            'Content-Type' => $existing->response_content_type,
            self::REPLAY_HEADER => 'true',
        ]));
    }

    private function storeOutcome(string $scope, string $key, Response $response): void
    {
        $query = IdempotencyKey::query()->where('scope', $scope)->where('key', $key);
        $status = $response->getStatusCode();

        // A rejected request produced no business effect (its transaction rolled back): the client may fix it and
        // retry with the same key. Only successful outcomes are replayed.
        if ($status >= 400) {
            $query->delete();

            return;
        }

        $query->update([
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'response_status' => $status,
            'response_body' => (string) $response->getContent(),
            'response_content_type' => $response->headers->get('Content-Type'),
            'updated_at' => now(),
        ]);
    }

    private function scopeFor(Request $request): string
    {
        $user = $request->user();

        return $user !== null
            ? 'user:'.$user->getAuthIdentifier()
            : 'guest:'.$request->ip();
    }

    private function fingerprint(Request $request): string
    {
        $content = $request->getContent();

        if ($content === '') {
            $content = (string) json_encode($request->all());
        }

        return hash('sha256', $request->method().'|'.$request->path().'|'.$content);
    }
}
