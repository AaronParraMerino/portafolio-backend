<?php

use Illuminate\Support\Facades\Route;

Route::prefix('administrador')->middleware('auth:sanctum')->group(function () {
    require __DIR__ . '/administrador/bitacoras.php';
    require __DIR__ . '/administrador/usuarios.php';
    require __DIR__ . '/administrador/sesiones.php';
    require __DIR__ . '/administrador/notificaciones.php';
    require __DIR__ . '/administrador/plantillas.php';
    require __DIR__ . '/administrador/eventos.php';
    require __DIR__ . '/administrador/publicantes.php';
});
