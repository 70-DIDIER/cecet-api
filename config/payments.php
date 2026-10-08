<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Return URL
    |--------------------------------------------------------------------------
    |
    | Where the payment provider sends the customer back after the hosted checkout.
    | The outcome is never read from this redirect: it is confirmed server to server.
    |
    */

    'return_url' => env('PAYMENT_RETURN_URL'),

];
