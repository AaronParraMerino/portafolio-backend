<?php

use App\Http\Controllers\Api\BusquedaController;
use Illuminate\Support\Facades\Route;

Route::post('/', [BusquedaController::class, 'buscar']);
