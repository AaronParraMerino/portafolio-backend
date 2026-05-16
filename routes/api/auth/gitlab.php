<?php

use App\Http\Controllers\Api\Auth\GitlabAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/gitlab/connect-url', [GitlabAuthController::class, 'connectUrl']);

    Route::delete('/gitlab/unlink', [GitlabAuthController::class, 'unlink']);
});

Route::get('/gitlab/redirect', [GitlabAuthController::class, 'redirect']);

Route::get('/gitlab/callback', [GitlabAuthController::class, 'callback']);
