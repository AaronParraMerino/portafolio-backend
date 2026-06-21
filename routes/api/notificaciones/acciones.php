<?php

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::patch('{userId}/grupo/read', [NotificationController::class, 'markGroupAsRead']);
Route::patch('{userId}/modulo/read', [NotificationController::class, 'markModuleAsRead']);
Route::patch('{userId}/read-all', [NotificationController::class, 'markAllAsRead']);
Route::patch('{userId}/{notificationId}/accion', [NotificationController::class, 'respondAction']);
Route::patch('{userId}/{notificationId}/read', [NotificationController::class, 'markAsRead']);
Route::patch('{userId}/{notificationId}/unread', [NotificationController::class, 'markAsUnread']);
