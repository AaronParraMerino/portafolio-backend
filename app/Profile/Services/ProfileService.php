<?php

namespace App\Profile\Services;

use App\Profile\Mock\ProfileMock;

class ProfileService
{
        /**
     * Obtiene el perfil completo de un usuario combinando datos de:
     * - usuario
     * - perfil
     * - enlaces
     * - visibilidad
     * (mock)
     */
    public function getProfile(int $userId): array
    {
        $usuario = collect(ProfileMock::usuarios())
            ->firstWhere('id_usuario', $userId);

        $perfil = collect(ProfileMock::perfiles())
            ->firstWhere('usuario_id', $userId);

        $enlaces = collect(ProfileMock::enlaces())
            ->where('usuario_id', $userId)
            ->values();

        $visibilidadRaw = collect(ProfileMock::visibilidad())
            ->where('usuario_id', $userId)
            ->pluck('visible', 'campo')
            ->toArray();

        return [
            'id' => $usuario['id_usuario'],
            'nombre' => $usuario['nombre'] . ' ' . $usuario['apellido'],
            'correo' => $usuario['correo'],
            'telefono' => $usuario['telefono'],
            'biografia' => $perfil['biografia'],
            'ciudad' => $perfil['ciudad'],
            'pais' => $perfil['pais'],

            'visibilidad' => [
                'nombre' => true,
                'correo' => $visibilidadRaw['email'] ?? false,
                'telefono' => $visibilidadRaw['telefono'] ?? false,
                'biografia' => true,
                'pais' => $visibilidadRaw['pais'] ?? false,
                'ciudad' => $visibilidadRaw['ciudad'] ?? false,
            ],

            'stats' => [
                'proyectos' => 4,
                'habilidades' => 8,
                'completitud' => 72,
            ],

            'enlaces' => $enlaces->map(fn ($e) => [
                'tipo' => $e['tipo'],
                'url' => $e['url'],
                'visible' => $e['visible'],
            ])->values(),

            'habilidades' => [
                ['id' => 1, 'nombre' => 'Laravel', 'tipo' => 'tecnica', 'nivel' => 'avanzado'],
                ['id' => 2, 'nombre' => 'React', 'tipo' => 'tecnica', 'nivel' => 'avanzado'],
            ],
        ];
    }
        /**
     * Actualiza parcialmente los datos del usuario en el mock.
     * Solo modifica los campos que no sean null.
     */

    private function updateUsuarioMock(int $userId, array $data): void
    {
        $usuarios = ProfileMock::usuarios();

        foreach ($usuarios as &$usuario) {
            if ($usuario['id_usuario'] === $userId) {
                foreach ($data as $key => $value) {
                    if (!is_null($value)) {
                        $usuario[$key] = $value;
                    }
                }
            }
        }
        ProfileMock::setUsuarios($usuarios);
    }
    /**
     * Actualiza parcialmente los datos del perfil en el mock.
     * Solo modifica los campos que no sean null.
     */

    private function updatePerfilMock(int $userId, array $data): void
    {
        $perfiles = ProfileMock::perfiles();

        foreach ($perfiles as &$perfil) {
            if ($perfil['usuario_id'] === $userId) {
                foreach ($data as $key => $value) {
                    if (!is_null($value)) {
                        $perfil[$key] = $value;
                    }
                }
            }
        }
        ProfileMock::setPerfiles($perfiles);
    }
    /**
     * Actualiza informacion general del perfil (usuario + perfil).
     * Luego retorna el perfil actualizado completo.
     */
    public function updateProfile(int $userId, array $data): array
    {
        $this->updateUsuarioMock($userId, [
            'nombre' => $data['nombre'] ?? null,
            'apellido' => $data['apellido'] ?? null,
            'correo' => $data['correo'] ?? null,
            'telefono' => $data['telefono'] ?? null,
        ]);

        $this->updatePerfilMock($userId, [
            'biografia' => $data['biografia'] ?? null,
            'ciudad' => $data['ciudad'] ?? null,
            'pais' => $data['pais'] ?? null,
        ]);

        return $this->getProfile($userId);
    }

    /**
     * Actualiza un campo específico de visibilidad en el mock.
     * Se usa internamente para aplicar cambios individuales.
     */

    private function updateVisibilityMock(int $userId, string $campo, bool $visible): void
    {
        $visibilidad = ProfileMock::visibilidad();

        foreach ($visibilidad as &$item) {
            if ($item['usuario_id'] === $userId && $item['campo'] === $campo) {
                $item['visible'] = $visible;
            }
        }

        ProfileMock::setVisibilidad($visibilidad);
    }

    /**
     * Actualiza múltiples campos de visibilidad de un usuario.
     * Ejemplo: ['correo' => true, 'telefono' => false]
     */

    public function updateVisibility(int $userId, array $data): array
    {
        foreach ($data as $campo => $visible) {
            $this->updateVisibilityMock($userId, $campo, filter_var($visible, FILTER_VALIDATE_BOOLEAN));
        }

        return $this->getProfile($userId);
    }
}





