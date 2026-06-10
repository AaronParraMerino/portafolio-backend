<?php

use App\Http\Controllers\Api\PublicanteEventoController;
use App\Http\Controllers\Api\PublicanteSolicitudController;
use Illuminate\Support\Facades\Route;

Route::prefix('publicante')->middleware(['auth:sanctum', 'account.writable'])->group(function () {
    Route::post('/solicitudes', [PublicanteSolicitudController::class, 'store']);

    Route::get('/eventos', [PublicanteEventoController::class, 'index']);
    Route::post('/eventos', [PublicanteEventoController::class, 'store']);
    Route::post('/eventos/{id}', [PublicanteEventoController::class, 'update'])->whereNumber('id');
    Route::put('/eventos/{id}', [PublicanteEventoController::class, 'update'])->whereNumber('id');
});
