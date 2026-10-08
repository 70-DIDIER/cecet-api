<?php

namespace App\Domain\Identity\Http\Middleware;

use App\Domain\Identity\Actions\ConfirmStepUp;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a recent email OTP confirmation on the current token (or cookie session) before a sensitive action.
 */
class EnsureStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        $confirmed = $token instanceof PersonalAccessToken
            ? $token->hasValidStepUp()
            : $request->hasSession() && (int) $request->session()->get(ConfirmStepUp::SESSION_KEY, 0) > now()->getTimestamp();

        if (! $confirmed) {
            throw IdentityException::stepUpRequired();
        }

        return $next($request);
    }
}
