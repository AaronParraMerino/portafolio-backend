<?php

use Illuminate\Support\Facades\Route;

Route::prefix('buscar')->middleware('auth:sanctum')->group(function () {
    require __DIR__ . '/busqueda/principal.php';
    require __DIR__ . '/busqueda/catalogos.php';
});
