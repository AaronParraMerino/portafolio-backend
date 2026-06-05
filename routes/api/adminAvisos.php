<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AdminAvisoController;

// para hacerlo sin autenticacion:
// Route::prefix('admin/avisos')->group(function () {

Route::prefix('admin/avisos')->middleware(['auth:sanctum', 'account.writable'])->group(function () {

    // Lista avisos para administracion
    Route::get('/', [AdminAvisoController::class, 'index']);

    // Muestra un aviso por id
    Route::get('{idAviso}', [AdminAvisoController::class, 'show']);

    // Crea un aviso
    Route::post('/', [AdminAvisoController::class, 'store']);

    // Actualiza un aviso
    Route::put('{idAviso}', [AdminAvisoController::class, 'update']);

    // Cambia solo el estado del aviso
    Route::patch('{idAviso}/estado', [AdminAvisoController::class, 'cambiarEstado']);

    // Cambia solo la prioridad del aviso
    Route::patch('{idAviso}/prioridad', [AdminAvisoController::class, 'cambiarPrioridad']);

    // Eliminacion logica del aviso
    Route::delete('{idAviso}', [AdminAvisoController::class, 'destroy']);

});
