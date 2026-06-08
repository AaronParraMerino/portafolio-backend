<?php

use App\Http\Controllers\Api\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::get('/preferencia-idioma', [UsuarioController::class, 'preferenciaIdioma']);
Route::patch('/preferencia-idioma', [UsuarioController::class, 'actualizarPreferenciaIdioma']);
