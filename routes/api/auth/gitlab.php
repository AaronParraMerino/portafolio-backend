<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/gitlab/connect-url', [AuthController::class, 'oauthConnectUrl'])
        ->defaults('provider', 'gitlab');

    Route::delete('/gitlab/unlink', [AuthController::class, 'oauthUnlink'])
        ->defaults('provider', 'gitlab');
});

Route::get('/gitlab/redirect', [AuthController::class, 'oauthRedirect'])
    ->defaults('provider', 'gitlab');

Route::get('/gitlab/callback', [AuthController::class, 'oauthCallback'])
    ->defaults('provider', 'gitlab');
