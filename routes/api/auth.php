<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/oauth/linked-providers', [AuthController::class, 'linkedProviders']);
        Route::post('/{provider}/connect-url', [AuthController::class, 'oauthConnectUrl'])
             ->where('provider', 'google|github|gitlab|discord');
           Route::delete('/{provider}/unlink', [AuthController::class, 'oauthUnlink'])
               ->where('provider', 'google|github|gitlab|discord');
    });
    Route::post('/google', [AuthController::class, 'googleAuth']);
    Route::post('/confirm-link', [AuthController::class, 'confirmOAuthLink']);

    // OAuth redirect & callback — GitHub, GitLab, Discord
    Route::get('/{provider}/redirect', [AuthController::class, 'oauthRedirect'])
         ->where('provider', 'github|gitlab|discord');
    // Google callback is used by the authenticated connect flow (state token)
    Route::get('/{provider}/callback', [AuthController::class, 'oauthCallback'])
        ->where('provider', 'google|github|gitlab|discord');
});