<?php

use App\Http\Controllers\Api\MensajeriaController;
use Illuminate\Support\Facades\Route;

Route::post('solicitudes', [MensajeriaController::class, 'crearSolicitud'])
    ->middleware('account.writable');

Route::patch('solicitudes/{solicitudId}/aceptar', [MensajeriaController::class, 'aceptarSolicitud'])
    ->whereNumber('solicitudId')
    ->middleware('account.writable');

Route::patch('solicitudes/{solicitudId}/rechazar', [MensajeriaController::class, 'rechazarSolicitud'])
    ->whereNumber('solicitudId')
    ->middleware('account.writable');
