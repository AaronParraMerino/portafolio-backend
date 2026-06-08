<?php

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('{userId}/modulos', [NotificationController::class, 'modulos']);
Route::get('{userId}/modulos/{modulo}', [NotificationController::class, 'segundoNivel']);
Route::get('{userId}/modulos/{modulo}/grupos/{contextoReferencia}', [NotificationController::class, 'mensajesGrupo']);
Route::get('{userId}/pendientes/count', [NotificationController::class, 'countUnread']);
