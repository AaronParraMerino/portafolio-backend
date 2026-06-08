<?php

use App\Http\Controllers\Api\Administrador\UsuarioPlantillaController;
use Illuminate\Support\Facades\Route;

Route::get('/usuarios/plantillas', [UsuarioPlantillaController::class, 'index']);
Route::post('/usuarios/plantillas', [UsuarioPlantillaController::class, 'store']);
Route::put('/usuarios/plantillas/{id}', [UsuarioPlantillaController::class, 'update'])->whereNumber('id');
Route::delete('/usuarios/plantillas/{id}', [UsuarioPlantillaController::class, 'destroy'])->whereNumber('id');
Route::post('/usuarios/plantillas/{id}/usar', [UsuarioPlantillaController::class, 'useTemplate'])->whereNumber('id');
