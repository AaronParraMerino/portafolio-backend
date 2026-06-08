<?php

use App\Http\Controllers\Api\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::post('/cambiar-password', [UsuarioController::class, 'cambiarPassword']);
