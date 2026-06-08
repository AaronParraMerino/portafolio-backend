<?php

use App\Http\Controllers\Api\Administrador\UsuarioSesionController;
use Illuminate\Support\Facades\Route;

Route::get('/usuarios/{id}/sesiones', [UsuarioSesionController::class, 'index'])->whereNumber('id');
Route::delete('/usuarios/{id}/sesiones', [UsuarioSesionController::class, 'destroyAll'])->whereNumber('id');
Route::delete('/usuarios/{id}/sesiones/{sessionId}', [UsuarioSesionController::class, 'destroy'])
    ->whereNumber('id')
    ->whereNumber('sessionId');
