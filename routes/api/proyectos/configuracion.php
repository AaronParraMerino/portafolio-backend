<?php

use App\Http\Controllers\Api\Proyecto\ProyectoConfiguracionController;
use Illuminate\Support\Facades\Route;

Route::get('/{id}/configuration', [ProyectoConfiguracionController::class, 'show']);
Route::put('/{id}/configuration', [ProyectoConfiguracionController::class, 'update']);
