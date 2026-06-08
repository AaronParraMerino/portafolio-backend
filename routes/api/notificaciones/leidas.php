<?php

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('{userId}/leidas', [NotificationController::class, 'readNotifications']);
Route::get('{userId}/leidas/modulos', [NotificationController::class, 'readModules']);
Route::get('{userId}/leidas/modulos/{modulo}', [NotificationController::class, 'readSecondLevel']);
Route::get('{userId}/leidas/modulos/{modulo}/grupos/{contextoReferencia}', [NotificationController::class, 'readGroupMessages']);
