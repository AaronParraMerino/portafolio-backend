<?php

use App\Http\Controllers\Api\Administrador\NotificacionController;
use Illuminate\Support\Facades\Route;

Route::post('/notificaciones', [NotificacionController::class, 'store']);
