<?php

return [
    // Didit selected by CECT. No API credentials means fail closed.
    // Manual mode is ONLY for isolated legacy tests.
    'provider' => env('KYC_PROVIDER', 'didit'),
    'max_file_kb' => 2048,
    'didit' => [
        'base_url' => 'https://verification.didit.me',
        'api_key' => env('DIDIT_API_KEY'),
        'workflow_id' => env('DIDIT_WORKFLOW_ID'),
        'kyb_workflow_id' => env('DIDIT_KYB_WORKFLOW_ID'),
        'application_id' => env('DIDIT_APPLICATION_ID'),
        'webhook_secret' => env('DIDIT_WEBHOOK_SECRET'),
        'auto_approve' => filter_var(env('DIDIT_AUTO_APPROVE', false), FILTER_VALIDATE_BOOLEAN),
        'environment' => env('DIDIT_ENVIRONMENT', 'sandbox'),
        'document_only_test' => filter_var(env('DIDIT_DOCUMENT_ONLY_TEST', false), FILTER_VALIDATE_BOOLEAN),
        'allowed_redirect_hosts' => ['verify.didit.me'],
    ],
];
