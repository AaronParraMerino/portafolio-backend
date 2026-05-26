<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\NotificationController;

// para hacerlo sin autenticacion:
//Route::prefix('notificaciones')->group(function () {

Route::prefix('notificaciones')->middleware('auth:sanctum')->group(function () {

    Route::get('{userId}', [NotificationController::class, 'index']);

    Route::get('{userId}/pendientes/count', [NotificationController::class, 'countUnread']);

    Route::patch('{userId}/{notificationId}/read', [NotificationController::class, 'markAsRead']);

    Route::patch('{userId}/read-many', [NotificationController::class, 'markManyAsRead']);

    Route::patch('{userId}/read-all', [NotificationController::class, 'markAllAsRead']);

});