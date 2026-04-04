<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
############# funciones de rutas para usuarios #############
require __DIR__.'/api/auth.php';


############# datos tabla usuario ##########
require __DIR__.'/api/usuarios.php';



#############################################
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