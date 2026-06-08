<?php

use App\Http\Controllers\Api\Administrador\EventoController;
use Illuminate\Support\Facades\Route;

Route::get('/eventos/workspace', [EventoController::class, 'workspace']);
Route::get('/eventos', [EventoController::class, 'index']);
Route::patch('/eventos/{id}/activar', [EventoController::class, 'eventAction'])
    ->whereNumber('id')
    ->defaults('action', 'activar');
Route::patch('/eventos/{id}/pausar', [EventoController::class, 'eventAction'])
    ->whereNumber('id')
    ->defaults('action', 'pausar');
Route::patch('/eventos/{id}/suspender', [EventoController::class, 'eventAction'])
    ->whereNumber('id')
    ->defaults('action', 'suspender');
Route::delete('/eventos/{id}', [EventoController::class, 'destroy'])->whereNumber('id');
