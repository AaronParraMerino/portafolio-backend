<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__.'/api/seccion.php';
############# funciones de rutas para usuarios #############
require __DIR__.'/api/auth.php';
require __DIR__.'/api/recuperacion.php';

############# datos tabla usuario ##########
require __DIR__.'/api/usuarios.php';
require __DIR__.'/api/profile.php';

require __DIR__.'/api/experiencias.php';
require __DIR__.'/api/habilidades.php';

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