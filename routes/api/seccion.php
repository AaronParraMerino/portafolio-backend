<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SeccionController;

Route::prefix('seccion')->group(function () {
    Route::post('/basic', [SeccionController::class, 'store']);
    Route::post('/advanced', [SeccionController::class, 'hardware']);
});