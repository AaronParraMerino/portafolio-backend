<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

############# funciones de rutas para usuarios #############
// base_path garantiza que encuentre la carpeta desde la raíz del proyecto
require base_path('routes/api/auth.php');
require base_path('routes/api/recuperacion.php');

############# datos tabla usuario ##########
require base_path('routes/api/usuarios.php');
require base_path('routes/api/profile.php');
require base_path('routes/api/experiencias.php');

#############################################
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