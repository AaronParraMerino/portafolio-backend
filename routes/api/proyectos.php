<?php

use Illuminate\Support\Facades\Route;

require __DIR__ . '/proyectos/public.php';

Route::prefix('projects')->middleware(['auth:sanctum', 'account.writable'])->group(function () {
    require __DIR__ . '/proyectos/crud.php';
    require __DIR__ . '/proyectos/participantes.php';
    require __DIR__ . '/proyectos/configuracion.php';
    require __DIR__ . '/proyectos/enlaces.php';
    require __DIR__ . '/proyectos/multimedia.php';
});
