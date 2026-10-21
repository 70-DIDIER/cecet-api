<?php

namespace App\Domain\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSuperadminMtls
{
    public static function fingerprint(Request $request): ?string
    {
        // TRUST BOUNDARY: server parameters from the dedicated Nginx FastCGI vhost, NEVER HTTP headers.
        if ($request->server('CECT_MTLS_VERIFY') !== 'SUCCESS') {
            return null;
        }

        $encoded = (string) $request->server('CECT_MTLS_CERT', '');
        if ($encoded === '' || strlen($encoded) > 16000) {
            return null;
        }

        $pem = rawurldecode($encoded);
        if (! str_contains($pem, '-----BEGIN CERTIFICATE-----')) {
            return null;
        }

        $cert = openssl_x509_read($pem);
        if ($cert === false || ! openssl_x509_export($cert, $canonical)) {
            return null;
        }

        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $canonical), true);

        return $der === false ? null : hash('sha256', $der);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('superadmin.enabled')) {
            abort(503, 'Accès SuperAdmin non activé.');
        }

        if (self::fingerprint($request) === null) {
            abort(403, 'Certificat client obligatoire.');
        }

        return $next($request);
    }
}
