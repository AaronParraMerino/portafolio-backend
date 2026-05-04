<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
    Route::post('/google', [AuthController::class, 'googleAuth']);
    Route::post('/confirm-link', [AuthController::class, 'confirmOAuthLink']);

    // OAuth redirect & callback — GitHub, GitLab, Discord
    Route::get('/{provider}/redirect', [AuthController::class, 'oauthRedirect'])
         ->where('provider', 'github|gitlab|discord');
    Route::get('/{provider}/callback', [AuthController::class, 'oauthCallback'])
         ->where('provider', 'github|gitlab|discord');
});