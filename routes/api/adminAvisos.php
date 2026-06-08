<?php

use Illuminate\Support\Facades\Route;

Route::prefix('admin/avisos')->middleware(['auth:sanctum', 'account.writable'])->group(function () {
    require __DIR__ . '/adminAvisos/consultas.php';
    require __DIR__ . '/adminAvisos/crud.php';
    require __DIR__ . '/adminAvisos/estado.php';
});
