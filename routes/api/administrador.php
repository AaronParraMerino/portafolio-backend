<?php

use App\Http\Controllers\Api\Administrador\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::prefix('administrador')->middleware('auth:sanctum')->group(function () {
    Route::get('/usuarios', [UsuarioController::class, 'index']);
    Route::delete('/usuarios/{id}', [UsuarioController::class, 'inactivate'])->whereNumber('id');
    Route::get('/usuarios/{id}/sesiones', [UsuarioController::class, 'sessions'])->whereNumber('id');
    Route::delete('/usuarios/{id}/sesiones', [UsuarioController::class, 'closeAllSessions'])->whereNumber('id');
    Route::delete('/usuarios/{id}/sesiones/{sessionId}', [UsuarioController::class, 'closeSession'])
        ->whereNumber('id')
        ->whereNumber('sessionId');
});
