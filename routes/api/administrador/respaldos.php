<?php

use App\Http\Controllers\Api\Administrador\RespaldoController;
use Illuminate\Support\Facades\Route;

Route::get('/respaldos', [RespaldoController::class, 'index']);
Route::post('/respaldos/generar', [RespaldoController::class, 'generate']);
