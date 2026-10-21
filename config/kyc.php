<?php

return [
    // Default is ALWAYS manual. Document OCR or matching entered fields does not prove identity.
    'provider' => env('KYC_PROVIDER', 'manual'),
    'webhook_secret' => env('KYC_PROVIDER_WEBHOOK_SECRET'),
    'max_file_kb' => 2048,
];
