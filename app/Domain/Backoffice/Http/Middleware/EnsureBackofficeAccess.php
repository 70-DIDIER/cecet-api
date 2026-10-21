<?php

namespace App\Domain\Backoffice\Http\Middleware;

use App\Domain\Backoffice\Menu\BackofficeMenu;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\StaffAccessPolicy;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The back-office is reserved to active internal accounts signed in with a session. Anything else is sent back to
 * the sign-in page; a session that no longer qualifies (suspended account, role change to member…) is closed.
 */
class EnsureBackofficeAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            return redirect()->guest(route('backoffice.login'));
        }

        if (! $user->isStaff() || $user->status !== UserStatus::Active || ($user->isSuperadmin() && config('superadmin.enabled'))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('backoffice.login')->withErrors(['identifier' => 'Ce compte n\'a plus accès au back-office.']);
        }

        if (config('staff_guardian.enabled') && ! $user->isSuperadmin()
            && ($request->session()->get('staff_guardian_user') !== $user->id
                || ! app(StaffAccessPolicy::class)->allows($user))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('backoffice.login')
                ->withErrors(['identifier' => 'Reconnectez-vous avec le code envoyé aux trois adresses et pendant vos horaires autorisés.']);
        }

        // Shared here (not by a layout composer): the content of a child view is rendered before its layout.
        View::share([
            'currentUser' => $user,
            'currentUserRole' => $user->isSuperadmin() ? 'Superadmin' : ($user->roles->first()->label ?? 'Personnel'),
            'menu' => app(BackofficeMenu::class)->for($user),
        ]);

        return $next($request);
    }
}
