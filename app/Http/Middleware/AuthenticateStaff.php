<?php

namespace App\Http\Middleware;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Models\Contracts\FilamentUser;

/**
 * Back-office access: a signed-in account that is not an active staff member (a member whose session shares the same
 * browser, a suspended account) is signed out and sent to the login page instead of getting a bare 403.
 */
class AuthenticateStaff extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();
        $user = $guard->user();

        if ($user instanceof FilamentUser && ! $user->canAccessPanel(Filament::getPanel('admin'))) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        parent::authenticate($request, $guards);
    }
}
