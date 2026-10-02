<?php

use App\Http\Controllers\Api\Auth\GoogleAuthController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class);

// Google OAuth callback runs on the web middleware stack (encrypted session
// cookies). After validating the HMAC-signed state it signs the user into an
// HttpOnly session — no bearer token ever reaches the browser URL. The exact
// URI is kept so the Google Console redirect URI stays unchanged; mobile uses
// /api/v1/mobile/auth/google with its own bearer flow.
Route::get('api/v1/auth/google/callback', [GoogleAuthController::class, 'callback'])
    ->middleware('throttle:30,1');
