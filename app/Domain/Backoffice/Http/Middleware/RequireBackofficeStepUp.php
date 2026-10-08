<?php

namespace App\Domain\Backoffice\Http\Middleware;

use App\Domain\Identity\Actions\ConfirmStepUp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive back-office actions need a recent e-mail code confirmation. When it is missing the user is sent to the
 * confirmation page and comes back to the page they were on (never replaying a form submission).
 */
class RequireBackofficeStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((int) $request->session()->get(ConfirmStepUp::SESSION_KEY, 0) > now()->getTimestamp()) {
            return $next($request);
        }

        $return = $request->isMethod('GET') ? $request->getRequestUri() : (string) (parse_url((string) $request->headers->get('referer'), PHP_URL_PATH) ?: route('backoffice.dashboard', absolute: false));

        return redirect()->route('backoffice.step-up', ['return' => $return])
            ->with('warning', 'Cette action est sensible : confirmez votre identité avec un code envoyé par e-mail, puis recommencez.');
    }
}
