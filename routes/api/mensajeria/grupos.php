<?php

use App\Http\Controllers\Api\MensajeriaController;
use Illuminate\Support\Facades\Route;

Route::post('grupos', [MensajeriaController::class, 'crearGrupo']);

Route::post('grupos/{chatId}/invitaciones', [MensajeriaController::class, 'invitarGrupo'])
    ->whereNumber('chatId');

Route::patch('grupos/{chatId}/salir', [MensajeriaController::class, 'salirGrupo'])
    ->whereNumber('chatId');
