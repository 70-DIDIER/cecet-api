<?php

use App\Domain\Identity\Http\Middleware\RequireSuperadminMtls;
use Illuminate\Http\Request;

it('never trusts attacker-supplied HTTP headers as client TLS attestation', function () {
    $request = Request::create('/api/v1/superadmin/status', 'GET', server: [
        'HTTP_CECT_MTLS_VERIFY' => 'SUCCESS',
        'HTTP_CECT_MTLS_CERT' => 'forged',
        'HTTP_X_CECT_MTLS_VERIFY' => 'SUCCESS',
        'HTTP_X_CECT_MTLS_CERT' => 'forged',
    ]);

    expect(RequireSuperadminMtls::fingerprint($request))->toBeNull();
});

it('requires successful TLS verification before accepting certificate bytes', function () {
    $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    expect($private)->not->toBeFalse();
    $csr = openssl_csr_new(['commonName' => 'temporary-test-client'], $private);
    $cert = openssl_csr_sign($csr, null, $private, 1);
    openssl_x509_export($cert, $pem);
    $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);

    $unverified = Request::create('/', 'GET', server: [
        'CECT_MTLS_VERIFY' => 'FAILED',
        'CECT_MTLS_CERT' => rawurlencode($pem),
    ]);
    $verified = Request::create('/', 'GET', server: [
        'CECT_MTLS_VERIFY' => 'SUCCESS',
        'CECT_MTLS_CERT' => rawurlencode($pem),
    ]);

    expect(RequireSuperadminMtls::fingerprint($unverified))->toBeNull()
        ->and(RequireSuperadminMtls::fingerprint($verified))->toBe(hash('sha256', $der));
});
