<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\EventoInscripcionController;

// para hacerlo sin autenticacion:
//Route::prefix('eventos')->group(function () {

Route::get('eventos/publicos', [EventoInscripcionController::class, 'publicos']);

Route::prefix('eventos')->middleware('auth:sanctum')->group(function () {

    // Lista eventos visibles para el home del usuario
    Route::get('{userId}', [EventoInscripcionController::class, 'index']);

    // Inscribe al usuario en un evento
    Route::post('{userId}/{eventoId}/inscribirse', [EventoInscripcionController::class, 'inscribirse']);

    // Desinscribe al usuario de un evento
    Route::post('{userId}/{eventoId}/desinscribirse', [EventoInscripcionController::class, 'desinscribirse']);

});
