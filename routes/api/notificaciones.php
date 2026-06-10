<?php

use Illuminate\Support\Facades\Route;

Route::prefix('notificaciones')->middleware(['auth:sanctum', 'account.writable'])->group(function () {
    require __DIR__ . '/notificaciones/pendientes.php';
    require __DIR__ . '/notificaciones/leidas.php';
    require __DIR__ . '/notificaciones/acciones.php';
});
