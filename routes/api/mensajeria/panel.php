<?php

use App\Http\Controllers\Api\MensajeriaController;
use Illuminate\Support\Facades\Route;

Route::get('panel', [MensajeriaController::class, 'panel']);

Route::get('perfil/{usuarioId}/contacto', [MensajeriaController::class, 'estadoContacto'])
    ->whereNumber('usuarioId');
