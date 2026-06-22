<?php

use App\Http\Controllers\Api\MensajeriaController;
use Illuminate\Support\Facades\Route;

Route::post('grupos', [MensajeriaController::class, 'crearGrupo'])
    ->middleware('account.writable');

Route::post('grupos/{chatId}/invitaciones', [MensajeriaController::class, 'invitarGrupo'])
    ->whereNumber('chatId')
    ->middleware('account.writable');

Route::patch('grupos/{chatId}/salir', [MensajeriaController::class, 'salirGrupo'])
    ->whereNumber('chatId')
    ->middleware('account.writable');
