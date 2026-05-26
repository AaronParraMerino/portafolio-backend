<?php

use App\Http\Controllers\Api\Administrador\EventoController;
use Illuminate\Support\Facades\Route;

Route::prefix('administrador/eventos')->middleware('auth:sanctum')->group(function () {
    Route::get('/workspace', [EventoController::class, 'workspace']);
    Route::post('/', [EventoController::class, 'store']);
    Route::put('/{id}', [EventoController::class, 'update'])->whereNumber('id');
    Route::post('/{id}/duplicar', [EventoController::class, 'duplicate'])->whereNumber('id');
    Route::patch('/{id}/cancelar', [EventoController::class, 'cancel'])->whereNumber('id');

    Route::post('/comunicaciones', [EventoController::class, 'storeCommunication']);
    Route::put('/comunicaciones/{id}', [EventoController::class, 'updateCommunication'])->whereNumber('id');
    Route::patch('/comunicaciones/{id}/archivar', [EventoController::class, 'archiveCommunication'])->whereNumber('id');

    Route::post('/plantillas', [EventoController::class, 'storeTemplate']);
    Route::put('/plantillas/{id}', [EventoController::class, 'updateTemplate'])->whereNumber('id');
});
