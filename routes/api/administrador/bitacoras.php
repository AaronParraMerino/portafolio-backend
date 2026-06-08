<?php

use App\Http\Controllers\Api\Administrador\BitacoraController;
use Illuminate\Support\Facades\Route;

Route::get('/bitacoras', [BitacoraController::class, 'index']);
