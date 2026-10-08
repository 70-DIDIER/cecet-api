<?php

namespace App\Domain\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Propagates a correlation identifier through logs, audit events, queued jobs
 * and the response, reusing the client's value only when it is well formed.
 */
class AttachCorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    private const ACCEPTED_FORMAT = '/^[A-Za-z0-9._-]{8,64}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');

        $correlationId = preg_match(self::ACCEPTED_FORMAT, $incoming) === 1
            ? $incoming
            : (string) Str::ulid();

        Context::add('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
