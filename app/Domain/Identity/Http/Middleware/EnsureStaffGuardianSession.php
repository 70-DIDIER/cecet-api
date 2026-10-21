<?php

namespace App\Domain\Identity\Http\Middleware;

use App\Domain\Identity\StaffAccessPolicy;
use App\Domain\Identity\Enums\UserStatus;
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
        if (! $user instanceof User) {
            return $next($request);
        }
        // Do not rely on an authenticated User object cached earlier in a
        // long-running worker or between requests in an SPA session.
        // Suspension/closure must take effect immediately without logout.
        $user->refresh();
        // A previously issued cookie or bearer token must not grant access
        // after suspension/closure; this applies even with guardian mode OFF.
        // Pending-payment members may authenticate solely to complete their
        // membership, but staff must be fully active.
        if ($request->is('api/v1/auth/logout')) {
            return $next($request);
        }
        if (! $user->status->canAuthenticate()
            || ($user->isStaff() && $user->status !== UserStatus::Active)) {
            abort(401, 'Compte suspendu, non activé ou clôturé.');
        }
        if (! config('staff_guardian.enabled') || ! $user->isStaff() || $user->isSuperadmin()) {
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
