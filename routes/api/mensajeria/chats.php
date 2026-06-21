<?php

use App\Http\Controllers\Api\MensajeriaController;
use Illuminate\Support\Facades\Route;

Route::get('chats/{chatId}/mensajes', [MensajeriaController::class, 'mensajes'])
    ->whereNumber('chatId');

Route::post('chats/{chatId}/mensajes', [MensajeriaController::class, 'enviarMensaje'])
    ->whereNumber('chatId');

Route::patch('chats/{chatId}/archivar', [MensajeriaController::class, 'archivar'])
    ->whereNumber('chatId');

Route::patch('chats/{chatId}/desarchivar', [MensajeriaController::class, 'desarchivar'])
    ->whereNumber('chatId');

Route::patch('chats/{chatId}/bloquear', [MensajeriaController::class, 'bloquear'])
    ->whereNumber('chatId');

Route::patch('chats/{chatId}/desbloquear', [MensajeriaController::class, 'desbloquear'])
    ->whereNumber('chatId');
