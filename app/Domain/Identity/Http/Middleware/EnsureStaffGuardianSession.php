<?php

namespace App\Domain\Identity\Http\Middleware;

use App\Domain\Identity\StaffAccessPolicy;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied after auth:sanctum to every staff API request. Older tokens and
 * password sessions cannot bypass the new three-inbox login or work window.
 */
final class EnsureStaffGuardianSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! config('staff_guardian.enabled') || ! $user instanceof User
            || ! $user->isStaff() || $user->isSuperadmin()) {
            return $next($request);
        }
        if ($request->is('api/v1/auth/logout')) {
            return $next($request);
        }
        abort_unless($request->hasSession()
            && $request->session()->get('staff_guardian_user') === $user->id, 403,
            'Connexion du personnel avec validation des trois adresses requise.');
        abort_unless(app(StaffAccessPolicy::class)->allows($user), 403,
            'Accès interdit en dehors de vos horaires autorisés.');
        return $next($request);
    }
}
