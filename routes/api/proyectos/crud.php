<?php

use App\Http\Controllers\Api\Proyecto\ProyectoConsultaController;
use App\Http\Controllers\Api\Proyecto\ProyectoCrudController;
use Illuminate\Support\Facades\Route;

Route::get('/usuario/{userId}', [ProyectoConsultaController::class, 'indexByUsuario']);
Route::get('/{id}', [ProyectoConsultaController::class, 'show']);
Route::post('/', [ProyectoCrudController::class, 'store']);
Route::put('/{id}', [ProyectoCrudController::class, 'update']);
Route::delete('/{id}', [ProyectoCrudController::class, 'destroy']);
