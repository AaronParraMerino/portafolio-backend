<?php

use App\Http\Controllers\Api\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::post('/google', [GoogleAuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'account.writable'])->group(function () {
    Route::post('/google/connect-url', [GoogleAuthController::class, 'connectUrl']);
    Route::delete('/google/unlink', [GoogleAuthController::class, 'unlink']);
});

// Google callback is used by the authenticated connect flow (state token).
Route::get('/google/callback', [GoogleAuthController::class, 'callback']);
