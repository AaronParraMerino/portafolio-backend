<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

// Ruta de prueba para verificar la conexión
Route::get('/ping', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Backend conectado correctamente',
        'timestamp' => now(),
    ]);
});

// Ejemplo de ruta real
Route::get('/cotizaciones', function () {
    return response()->json([
        'data' => [],
        'message' => 'Lista de cotizaciones'
    ]);
});

// Rutas de autenticación
Route::post('/register', [AuthController::class, 'register']);