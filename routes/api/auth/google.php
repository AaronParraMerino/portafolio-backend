<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/google', [AuthController::class, 'googleAuth']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/google/connect-url', [AuthController::class, 'oauthConnectUrl'])
        ->defaults('provider', 'google');

    Route::delete('/google/unlink', [AuthController::class, 'oauthUnlink'])
        ->defaults('provider', 'google');
});

// Google callback is used by the authenticated connect flow (state token).
Route::get('/google/callback', [AuthController::class, 'oauthCallback'])
    ->defaults('provider', 'google');
