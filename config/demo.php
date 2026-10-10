<?php

/*
|--------------------------------------------------------------------------
| Demonstration data (development and recette only)
|--------------------------------------------------------------------------
|
| Used by `php artisan db:seed --class=DemoClientSeeder`, which refuses to run in production.
|
*/

return [
    'client_email' => env('DEMO_CLIENT_EMAIL', 'tandemodon55@gmail.com'),
    'merchant_email' => env('DEMO_MERCHANT_EMAIL', 'marchand.demo@cect.example'),
    'staff_email' => env('DEMO_STAFF_EMAIL', 'superadmin.demo@cect.example'),
    'password' => env('DEMO_PASSWORD', 'Demo2026cect'),
];
