<?php

use App\Http\Controllers\Api\MensajeriaController;
use Illuminate\Support\Facades\Route;

Route::prefix('mensajeria')->middleware(['auth:sanctum'])->group(function () {
    require __DIR__ . '/mensajeria/panel.php';
    require __DIR__ . '/mensajeria/solicitudes.php';
    require __DIR__ . '/mensajeria/grupos.php';
    require __DIR__ . '/mensajeria/invitaciones.php';
    require __DIR__ . '/mensajeria/chats.php';
});
