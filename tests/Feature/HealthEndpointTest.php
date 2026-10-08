<?php

use App\Domain\Shared\Http\Middleware\AttachCorrelationId;

it('reports a healthy platform', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'checks' => ['database' => 'ok', 'cache' => 'ok'],
        ])
        ->assertJsonStructure(['status', 'version', 'checks', 'time']);
});

it('generates a correlation id when the client sends none', function () {
    $response = $this->getJson('/api/v1/health');

    expect($response->headers->get(AttachCorrelationId::HEADER))->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('reuses a well-formed client correlation id', function () {
    $this->getJson('/api/v1/health', [AttachCorrelationId::HEADER => 'client-trace-123'])
        ->assertHeader(AttachCorrelationId::HEADER, 'client-trace-123');
});

it('replaces a malformed client correlation id', function () {
    $response = $this->getJson('/api/v1/health', [AttachCorrelationId::HEADER => "bad id\n<script>"]);

    expect($response->headers->get(AttachCorrelationId::HEADER))->not->toBe("bad id\n<script>")
        ->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('sends the security headers on every API response, errors included', function () {
    foreach (['/api/v1/health', '/api/v1/me'] as $uri) {
        $this->getJson($uri)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    $this->getJson('/api/v1/health')->assertHeaderMissing('Strict-Transport-Security');
    $this->getJson('https://localhost/api/v1/health')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
