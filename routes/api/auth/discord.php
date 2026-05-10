<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/discord/connect-url', [AuthController::class, 'oauthConnectUrl'])
        ->defaults('provider', 'discord');

    Route::delete('/discord/unlink', [AuthController::class, 'oauthUnlink'])
        ->defaults('provider', 'discord');
});

Route::get('/discord/redirect', [AuthController::class, 'oauthRedirect'])
    ->defaults('provider', 'discord');

Route::get('/discord/callback', [AuthController::class, 'oauthCallback'])
    ->defaults('provider', 'discord');
