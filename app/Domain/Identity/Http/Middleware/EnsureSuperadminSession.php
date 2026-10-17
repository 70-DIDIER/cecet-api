<?php

namespace App\Domain\Identity\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperadminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isSuperadmin() || ! config('superadmin.enabled')) {
            return $next($request);
        }

        $fingerprint = RequireSuperadminMtls::fingerprint($request);
        $keyId = $request->hasSession() ? $request->session()->get('superadmin_verified_key') : null;
        if (! $fingerprint || ! $keyId || ! hash_equals($fingerprint, (string) $request->session()->get('superadmin_verified_cert'))) {
            abort(403, 'Session SuperAdmin cryptographique requise.');
        }

        $active = DB::table('superadmin_access_keys')
            ->where('id', $keyId)->where('user_id', $user->id)
            ->where('cert_sha256', $fingerprint)->where('status', 'active')
            ->where('expires_at', '>', now())->exists();

        if (! $active) {
            abort(403, 'Clé SuperAdmin expirée ou révoquée.');
        }

        return $next($request);
    }
}
