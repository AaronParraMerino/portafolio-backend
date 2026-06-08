<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account.writable'])->prefix('usuarios')->group(function () {
    require __DIR__ . '/usuarios/preferencias.php';
    require __DIR__ . '/usuarios/seguridad.php';
    require __DIR__ . '/usuarios/crud.php';
});
