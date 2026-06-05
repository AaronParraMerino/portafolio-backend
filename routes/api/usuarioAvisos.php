<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UsuarioAvisoController;

Route::prefix('avisos')->group(function () {

    // Lista todos los avisos visibles para usuarios
    Route::get('/', [UsuarioAvisoController::class, 'index']);

    // Cuenta avisos visibles actuales
    Route::get('visibles/count', [UsuarioAvisoController::class, 'count']);

});