<?php

use App\Http\Controllers\Api\Administrador\DenunciaController;
use Illuminate\Support\Facades\Route;

Route::get('/denuncias', [DenunciaController::class, 'index']);
Route::get('/denuncias/{id}', [DenunciaController::class, 'show'])->whereNumber('id');
Route::patch('/denuncias/{id}', [DenunciaController::class, 'update'])->whereNumber('id');
