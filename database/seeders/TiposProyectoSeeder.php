<?php

namespace Database\Seeders;

use App\Models\TipoProyecto;
use Illuminate\Database\Seeder;

class TiposProyectoSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            ['nombre' => 'web',         'descripcion' => 'Aplicación o sitio web'],
            ['nombre' => 'movil',       'descripcion' => 'Aplicación móvil (Android/iOS)'],
            ['nombre' => 'movil_web',   'descripcion' => 'Aplicación híbrida móvil y web'],
            ['nombre' => 'desktop',     'descripcion' => 'Aplicación de escritorio'],
            ['nombre' => 'api',         'descripcion' => 'API / servicio de backend'],
            ['nombre' => 'datos',       'descripcion' => 'Ciencia de datos / análisis'],
            ['nombre' => 'juego',       'descripcion' => 'Videojuego'],
            ['nombre' => 'herramienta', 'descripcion' => 'Herramienta / CLI / utilidad'],
            ['nombre' => 'otro',        'descripcion' => 'Otro tipo de proyecto'],
        ];

        foreach ($tipos as $tipo) {
            TipoProyecto::updateOrCreate(
                ['nombre' => $tipo['nombre']],
                ['descripcion' => $tipo['descripcion'], 'orden' => 0]
            );
        }
    }
}
