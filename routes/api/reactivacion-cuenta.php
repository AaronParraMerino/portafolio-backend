<?php

use App\Http\Controllers\Api\ReactivacionCuentaController;
use Illuminate\Support\Facades\Route;

Route::prefix('reactivacion-cuenta')->group(function () {
    Route::post('/solicitar', [ReactivacionCuentaController::class, 'solicitar']);
    Route::post('/confirmar', [ReactivacionCuentaController::class, 'confirmar']);
});
