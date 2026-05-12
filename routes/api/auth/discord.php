<?php

use App\Http\Controllers\Api\Auth\DiscordAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/discord/connect-url', [DiscordAuthController::class, 'connectUrl']);

    Route::delete('/discord/unlink', [DiscordAuthController::class, 'unlink']);
});

Route::get('/discord/redirect', [DiscordAuthController::class, 'redirect']);

Route::get('/discord/callback', [DiscordAuthController::class, 'callback']);
