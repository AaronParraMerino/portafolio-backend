<?php

use App\Http\Controllers\Api\MensajeriaController;
use Illuminate\Support\Facades\Route;

Route::post('solicitudes', [MensajeriaController::class, 'crearSolicitud']);

Route::patch('solicitudes/{solicitudId}/aceptar', [MensajeriaController::class, 'aceptarSolicitud'])
    ->whereNumber('solicitudId');

Route::patch('solicitudes/{solicitudId}/rechazar', [MensajeriaController::class, 'rechazarSolicitud'])
    ->whereNumber('solicitudId');
