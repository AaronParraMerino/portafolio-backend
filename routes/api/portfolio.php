<?php

use App\Http\Controllers\Api\PersonalizacionPortafolioController;
use Illuminate\Support\Facades\Route;

Route::prefix('portfolio')->group(function () {
    Route::get('{userId}/config', [PersonalizacionPortafolioController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::put('{userId}/config', [PersonalizacionPortafolioController::class, 'update']);
    });
});
