<?php

use App\Http\Controllers\Api\MensajeriaController;
use Illuminate\Support\Facades\Route;

Route::patch('invitaciones/{invitacionId}/aceptar', [MensajeriaController::class, 'aceptarInvitacion'])
    ->whereNumber('invitacionId')
    ->middleware('account.writable');

Route::patch('invitaciones/{invitacionId}/rechazar', [MensajeriaController::class, 'rechazarInvitacion'])
    ->whereNumber('invitacionId')
    ->middleware('account.writable');
