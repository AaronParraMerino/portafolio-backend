<?php

use App\Http\Controllers\Api\AdminAvisoController;
use Illuminate\Support\Facades\Route;

Route::patch('{idAviso}/estado', [AdminAvisoController::class, 'cambiarEstado']);
Route::patch('{idAviso}/prioridad', [AdminAvisoController::class, 'cambiarPrioridad']);
