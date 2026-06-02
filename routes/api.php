<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__.'/api/seccion.php';
############# funciones de rutas para usuarios #############
require __DIR__.'/api/auth.php';
require __DIR__.'/api/recuperacion.php';
require __DIR__.'/api/reactivacion-cuenta.php';

############# datos tabla usuario ##########
require __DIR__.'/api/usuarios.php';
require __DIR__.'/api/profile.php';
require __DIR__.'/api/administrador.php';
require __DIR__.'/api/eventos_admin.php';
require __DIR__.'/api/publicante.php';

require __DIR__.'/api/experiencias.php';
require __DIR__.'/api/habilidades.php';

require __DIR__.'/api/enlaces.php';
require __DIR__.'/api/home.php';
require __DIR__.'/api/eventos-personales.php';

################# apis portafolio ############################
require __DIR__.'/api/portfolio.php';
require __DIR__.'/api/tecnologia.php';
require __DIR__.'/api/proyectos.php';

################# apis para busqueda ############################
require __DIR__.'/api/busqueda.php';

##################api notificaiones##############################
require __DIR__.'/api/notificaciones.php';

##################api ver eventos##############################
require __DIR__.'/api/eventos.php';

##################api avisos##############################
require __DIR__.'/api/usuarioAvisos.php';
require __DIR__.'/api/adminAvisos.php';

#############################################################
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
