<?php

namespace App\Domain\Identity\Http\Middleware;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the back-office to active internal accounts. Fine-grained access uses permissions.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isStaff() || $user->status !== UserStatus::Active) {
            throw IdentityException::staffOnly();
        }

        return $next($request);
    }
}
