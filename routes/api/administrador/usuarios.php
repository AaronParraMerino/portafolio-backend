<?php

use App\Http\Controllers\Api\Administrador\UsuarioConsultaController;
use App\Http\Controllers\Api\Administrador\UsuarioEstadoController;
use App\Http\Controllers\Api\Administrador\UsuarioRolController;
use Illuminate\Support\Facades\Route;

Route::get('/usuarios', [UsuarioConsultaController::class, 'index']);
Route::patch('/usuarios/{id}/activar', [UsuarioEstadoController::class, 'activate'])->whereNumber('id');
Route::patch('/usuarios/{id}/pausar', [UsuarioEstadoController::class, 'pause'])->whereNumber('id');
Route::patch('/usuarios/{id}/bloquear', [UsuarioEstadoController::class, 'block'])->whereNumber('id');
Route::patch('/usuarios/{id}/rol', [UsuarioRolController::class, 'update'])->whereNumber('id');
Route::delete('/usuarios/{id}', [UsuarioEstadoController::class, 'inactivate'])->whereNumber('id');
