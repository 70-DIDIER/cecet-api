<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


// Backward-compatible landing page for old fake checkout links.
// Fake payments are confirmed only by the authenticated API endpoint,
// not by opening a URL. Disabled entirely outside staging.
if (app()->environment(['testing', 'staging'])) {
    Route::get('/fake-checkout/{transaction}', static function () {
        abort_unless(app()->environment('staging'), 404);

        return response()->view('payments.simulation-return')
            ->header('Cache-Control', 'no-store, private');
    })->where('transaction', 'fake_[a-z0-9]+')->name('fake-checkout.legacy');
}
