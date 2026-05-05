<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SeccionController;

Route::prefix('seccion')->group(function () {
    Route::post('/basic', [SeccionController::class, 'store']);
    Route::post('/advanced', [SeccionController::class, 'hardware']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/mis-sesiones', [SeccionController::class, 'mySessions']);
        Route::delete('/otras', [SeccionController::class, 'closeOtherSessions']);
        Route::delete('/{id}', [SeccionController::class, 'closeSession'])
            ->whereNumber('id');
    });
});