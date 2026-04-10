<?php

use App\Http\Controllers\Api\RecuperacionController;
use Illuminate\Support\Facades\Route;

Route::prefix('recuperacion')->group(function () {
    Route::post('/solicitar', [RecuperacionController::class, 'solicitar']);
    Route::post('/activar', [RecuperacionController::class, 'activar']);
    Route::post('/restablecer', [RecuperacionController::class, 'restablecer']);
});