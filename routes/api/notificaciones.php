<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\NotificationController;

// para hacerlo sin autenticacion:
// Route::prefix('notificaciones')->group(function () {

Route::prefix('notificaciones')->middleware('auth:sanctum')->group(function () {

    //Nivel 1 modulos con cantidad de no leidas
    Route::get('{userId}/modulos', [NotificationController::class, 'modulos']);

    //Nivel 2 grupos o mensajes directos por modulo
    Route::get('{userId}/modulos/{modulo}', [NotificationController::class, 'segundoNivel']);

    //Nivel 3 mensajes no leidos de un grupo
    Route::get('{userId}/modulos/{modulo}/grupos/{contextoReferencia}', [NotificationController::class, 'mensajesGrupo']);

    //Cuenta total de no leidas
    Route::get('{userId}/pendientes/count', [NotificationController::class, 'countUnread']);

    //Lista notificaciones leidas
    Route::get('{userId}/leidas', [NotificationController::class, 'readNotifications']);

    //Nivel 1 modulos con cantidad de leidas
    Route::get('{userId}/leidas/modulos', [NotificationController::class, 'readModules']);

    //Nivel 2 grupos o mensajes directos leidos por modulo
    Route::get('{userId}/leidas/modulos/{modulo}', [NotificationController::class, 'readSecondLevel']);

    //Nivel 3 mensajes leidos de un grupo
    Route::get('{userId}/leidas/modulos/{modulo}/grupos/{contextoReferencia}', [NotificationController::class, 'readGroupMessages']);




    //Marca un grupo como leido
    Route::patch('{userId}/grupo/read', [NotificationController::class, 'markGroupAsRead']);

    //Marca un modulo como leido
    Route::patch('{userId}/modulo/read', [NotificationController::class, 'markModuleAsRead']);

    //Marca todas las notificaciones como leidas
    Route::patch('{userId}/read-all', [NotificationController::class, 'markAllAsRead']);

    
    //Marca una notificacion como leida
    Route::patch('{userId}/{notificationId}/read', [NotificationController::class, 'markAsRead']);

    //Marca una notificacion como no leida
    Route::patch('{userId}/{notificationId}/unread', [NotificationController::class, 'markAsUnread']);

});
