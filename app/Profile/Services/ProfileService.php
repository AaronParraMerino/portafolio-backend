<?php

namespace App\Profile\Services;

use Illuminate\Support\Facades\DB;

class ProfileService
{
        /**
     * Obtiene el perfil completo de un usuario combinando datos de:
     * - usuario
     * - perfil
     * - enlaces
     * - visibilidad
     */
    public function getProfile(int $userId): array
    {
        // Usuario
        $usuario = DB::table('usuarios')
            ->where('id_usuario', $userId)
            ->first();

        if (!$usuario) {
            abort(404, 'Usuario no encontrado');
        }

        // Perfil
        $perfil = DB::table('perfiles')
            ->where('usuario_id', $userId)
            ->first();

        // Enlaces
        $enlaces = DB::table('enlaces_personales')
            ->where('usuario_id', $userId)
            ->get();

        // Visibilidad
        $visibilidadRaw = DB::table('visibilidad_campos')
            ->where('usuario_id', $userId)
            ->pluck('visible', 'campo')
            ->toArray();

        return [
            'id' => $usuario->id_usuario,
            'nombre' => $usuario->nombre . ' ' . $usuario->apellido,
            'correo' => $usuario->correo,
            'telefono' => $usuario->telefono,
            'biografia' => $perfil->biografia ?? null,
            'ciudad' => $perfil->ciudad ?? null,
            'pais' => $perfil->pais ?? null,

            'visibilidad' => [
                'nombre' => true,
                'correo' => $visibilidadRaw['correo'] ?? false,
                'telefono' => $visibilidadRaw['telefono'] ?? false,
                'biografia' => $visibilidadRaw['biografia'] ?? false,
                'pais' => $visibilidadRaw['pais'] ?? false,
                'ciudad' => $visibilidadRaw['ciudad'] ?? false,
            ],
            'stats' => [
                'proyectos' => 0,
                'habilidades' => 0,
                'completitud' => 0,
            ],

            'enlaces' => $enlaces->map(function ($e) {
                return [
                    'tipo' => $e->tipo,
                    'url' => $e->url,
                    'visible' => $e->visible,
                ];
            })->values(),

            'habilidades' => [
                ['id' => 1, 'nombre' => 'Laravel', 'tipo' => 'tecnica', 'nivel' => 'avanzado'],
                ['id' => 2, 'nombre' => 'React', 'tipo' => 'tecnica', 'nivel' => 'avanzado'],
            ],
        ];
    }
}





