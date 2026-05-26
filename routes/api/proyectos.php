<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProyectoController;

Route::prefix('projects')->middleware(['auth:sanctum', 'account.writable'])->group(function () {
    Route::get('/usuario/{userId}', [ProyectoController::class, 'indexByUsuario']);
    Route::get('/{id}/participants', [ProyectoController::class, 'participants']);
    Route::get('/{id}/participantes', [ProyectoController::class, 'participants']);
    Route::get('/{id}/configuration', [ProyectoController::class, 'configuration']);
    Route::put('/{id}/configuration', [ProyectoController::class, 'updateConfiguration']);
    Route::get('/{id}', [ProyectoController::class, 'show']);
    Route::post('/', [ProyectoController::class, 'store']);
    Route::put('/{id}', [ProyectoController::class, 'update']);
    Route::delete('/{id}', [ProyectoController::class, 'destroy']);
    Route::delete('/{id}/participation', [ProyectoController::class, 'detachParticipation']);
    Route::delete('/{id}/participants/{participacionId}', [ProyectoController::class, 'removeParticipant']);

    Route::patch('/{id}/links', [ProyectoController::class, 'updateLinks']);

    Route::post('/{id}/images', [ProyectoController::class, 'uploadImages']);
    Route::delete('/{id}/images', [ProyectoController::class, 'deleteImages']);
    Route::patch('/{id}/images/reorder', [ProyectoController::class, 'reorderImages']);

    Route::post('/{id}/documents', [ProyectoController::class, 'uploadDocuments']);
    Route::delete('/{id}/documents', [ProyectoController::class, 'deleteDocuments']);
    Route::patch('/{id}/documents/reorder', [ProyectoController::class, 'reorderDocuments']);
});
