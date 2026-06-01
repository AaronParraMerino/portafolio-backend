<?php

use App\Http\Controllers\Api\Administrador\EventoController;
use Illuminate\Support\Facades\Route;

Route::prefix('administrador')->middleware('auth:sanctum')->group(function () {
    Route::get('/eventos/workspace', [EventoController::class, 'workspace']);
    Route::get('/eventos', [EventoController::class, 'index']);
    Route::patch('/eventos/{id}/activar', [EventoController::class, 'eventAction'])
        ->whereNumber('id')
        ->defaults('action', 'activar');
    Route::patch('/eventos/{id}/pausar', [EventoController::class, 'eventAction'])
        ->whereNumber('id')
        ->defaults('action', 'pausar');
    Route::patch('/eventos/{id}/suspender', [EventoController::class, 'eventAction'])
        ->whereNumber('id')
        ->defaults('action', 'suspender');
    Route::delete('/eventos/{id}', [EventoController::class, 'destroy'])->whereNumber('id');

    Route::get('/publicantes/solicitudes', [EventoController::class, 'publisherRequests']);
    Route::patch('/publicantes/solicitudes/{id}/aprobar', [EventoController::class, 'approvePublisherRequest'])->whereNumber('id');
    Route::patch('/publicantes/solicitudes/{id}/rechazar', [EventoController::class, 'rejectPublisherRequest'])->whereNumber('id');
});
