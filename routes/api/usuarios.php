<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UsuarioController;

Route::middleware('auth:sanctum')->prefix('usuarios')->group(function () {
    Route::get('/', [UsuarioController::class, 'index']);
    Route::get('/{id}', [UsuarioController::class, 'show']);
    Route::post('/', [UsuarioController::class, 'store']);
    Route::put('/{id}', [UsuarioController::class, 'update']);
    Route::delete('/{id}', [UsuarioController::class, 'destroy']);
});