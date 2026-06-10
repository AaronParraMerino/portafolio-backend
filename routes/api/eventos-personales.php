<?php

use App\Http\Controllers\Api\EventoPersonalController;
use Illuminate\Support\Facades\Route;

Route::prefix('eventos-personales')->middleware(['auth:sanctum', 'account.writable'])->group(function () {
    Route::get('/', [EventoPersonalController::class, 'index']);
    Route::post('/', [EventoPersonalController::class, 'store']);
    Route::put('/{id}', [EventoPersonalController::class, 'update'])->whereNumber('id');
    Route::delete('/dia/{fecha}', [EventoPersonalController::class, 'destroyByDate']);
    Route::delete('/{id}', [EventoPersonalController::class, 'destroy'])->whereNumber('id');
});
