<?php

use App\Http\Controllers\Api\Administrador\UsuarioController;
use App\Http\Controllers\Api\Administrador\UsuarioPlantillaController;
use App\Http\Controllers\Api\Administrador\NotificacionController;
use App\Http\Controllers\Api\Administrador\BitacoraController;
use Illuminate\Support\Facades\Route;

Route::prefix('administrador')->middleware('auth:sanctum')->group(function () {
    Route::get('/bitacoras', [BitacoraController::class, 'index']);
    Route::get('/usuarios', [UsuarioController::class, 'index']);
    Route::patch('/usuarios/{id}/activar', [UsuarioController::class, 'activate'])->whereNumber('id');
    Route::patch('/usuarios/{id}/pausar', [UsuarioController::class, 'pause'])->whereNumber('id');
    Route::patch('/usuarios/{id}/bloquear', [UsuarioController::class, 'block'])->whereNumber('id');
    Route::patch('/usuarios/{id}/rol', [UsuarioController::class, 'updateRole'])->whereNumber('id');
    Route::delete('/usuarios/{id}', [UsuarioController::class, 'inactivate'])->whereNumber('id');
    Route::get('/usuarios/{id}/sesiones', [UsuarioController::class, 'sessions'])->whereNumber('id');
    Route::delete('/usuarios/{id}/sesiones', [UsuarioController::class, 'closeAllSessions'])->whereNumber('id');
    Route::delete('/usuarios/{id}/sesiones/{sessionId}', [UsuarioController::class, 'closeSession'])
        ->whereNumber('id')
        ->whereNumber('sessionId');
    Route::post('/notificaciones', [NotificacionController::class, 'store']);
    Route::get('/usuarios/plantillas', [UsuarioPlantillaController::class, 'index']);
    Route::post('/usuarios/plantillas', [UsuarioPlantillaController::class, 'store']);
    Route::put('/usuarios/plantillas/{id}', [UsuarioPlantillaController::class, 'update'])->whereNumber('id');
    Route::delete('/usuarios/plantillas/{id}', [UsuarioPlantillaController::class, 'destroy'])->whereNumber('id');
    Route::post('/usuarios/plantillas/{id}/usar', [UsuarioPlantillaController::class, 'useTemplate'])->whereNumber('id');
});
