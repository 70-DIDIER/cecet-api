<?php

return [
    // Disabled until a dedicated, certificate-verified Nginx vhost and first account are provisioned.
    'enabled' => (bool) env('SUPERADMIN_MTLS_ENABLED', false),
    'user_id' => env('SUPERADMIN_USER_ID'),
    'bootstrap_cert_sha256' => strtolower((string) env('SUPERADMIN_BOOTSTRAP_CERT_SHA256', '')),
    'key_days' => (int) env('SUPERADMIN_KEY_DAYS', 30),
    'otp_minutes' => 5,
    'max_attempts' => 5,
];
