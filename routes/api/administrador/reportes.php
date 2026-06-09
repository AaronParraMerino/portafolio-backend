<?php

use App\Http\Controllers\Api\Administrador\ReporteController;
use Illuminate\Support\Facades\Route;

Route::get('/reportes/resumen', [ReporteController::class, 'summary']);
