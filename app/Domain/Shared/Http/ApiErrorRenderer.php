<?php

namespace App\Domain\Shared\Http;

use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every API exception in the normalized error format expected by the clients:
 * { "code", "message", "details", "correlation_id" }.
 */
class ApiErrorRenderer
{
    public function __invoke(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        [$status, $code, $message, $details] = $this->describe($exception);

        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        return new JsonResponse([
            'code' => $code,
            'message' => $message,
            'details' => (object) $details,
            'correlation_id' => Context::get('correlation_id'),
        ], $status, $headers);
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>}
     */
    private function describe(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof ApiException => [$exception->status, $exception->errorCode, $exception->getMessage(), $exception->details],
            $exception instanceof ValidationException => [422, 'VALIDATION_FAILED', 'Les données fournies sont invalides.', ['fields' => $exception->errors()]],
            $exception instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Authentification requise.', []],
            $exception instanceof AuthorizationException,
            $exception instanceof AccessDeniedHttpException => [403, 'FORBIDDEN', 'Action non autorisée.', []],
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => [404, 'NOT_FOUND', 'Ressource introuvable.', []],
            $exception instanceof MethodNotAllowedHttpException => [405, 'METHOD_NOT_ALLOWED', 'Méthode HTTP non autorisée.', []],
            $exception instanceof ThrottleRequestsException => [429, 'TOO_MANY_REQUESTS', 'Trop de requêtes, réessayez plus tard.', []],
            $exception instanceof HttpExceptionInterface => [$exception->getStatusCode(), 'HTTP_'.$exception->getStatusCode(), $this->httpMessage($exception), []],
            default => [500, 'INTERNAL_ERROR', $this->internalMessage($exception), []],
        };
    }

    private function httpMessage(HttpExceptionInterface&Throwable $exception): string
    {
        return $exception->getMessage() !== '' ? $exception->getMessage() : 'Erreur HTTP.';
    }

    private function internalMessage(Throwable $exception): string
    {
        return config('app.debug') ? $exception->getMessage() : 'Une erreur interne est survenue.';
    }
}
