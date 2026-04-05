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

            'habilidades' => [
                ['id' => 1, 'nombre' => 'Laravel', 'tipo' => 'tecnica', 'nivel' => 'avanzado'],
                ['id' => 2, 'nombre' => 'React', 'tipo' => 'tecnica', 'nivel' => 'avanzado'],
            ],
        ];
    }

    /**
     * Actualiza parcialmente los datos de la tabla `usuarios`
     */
    
    private function updateUsuarios(int $userId, array $data): void
    {

        if (empty($data)) return;

        DB::table('usuarios')
            ->where('id_usuario', $userId)
            ->update($data);
    }
        /**
     * Inserta la visibilidad de un campo específico (para datos nuevos)
     * Siempre establece el campo como visible = true
     */

        private function visibility(int $userId, string $campo): void
        {
            DB::table('visibilidad_campos')->insertOrIgnore([
                'usuario_id' => $userId,
                'campo' => $campo,
                'visible' => DB::raw('true')
            ]);
        }
        /**
         * Crea un nuevo perfil para el usuario
         * - Inserta todos los campos recibidos
         * - Marca automáticamente como visibles todos los campos creados
         */
        private function crearPerfil(int $userId, array $data): void
        {
            $insertData = $data;
            $insertData['usuario_id'] = $userId;

            DB::table('perfiles')->insert($insertData);

            foreach ($data as $campo => $_) {
                $this->addVisibility($userId, $campo);
            }
        }

        /**
         * Actualiza un perfil existente.
         * - Solo actualiza campos cuyo valor haya cambiado
         * - Si un campo pasa de vacío/null a tener valor
         *   se activa automáticamente su visibilidad
         */

        private function updatePerfilExistente(int $userId, $perfil, array $data): void
        {
            $updates = [];

            foreach ($data as $campo => $nuevoValor) {

                $valorActual = $perfil->$campo ?? null;

                // Solo actualizar si el valor cambió
                if ($valorActual !== $nuevoValor) {

                    // Si antes estaba vacío y ahora tiene valor → visibilidad
                    if ((is_null($valorActual) || $valorActual === '') && $nuevoValor !== null && $nuevoValor !== '') {
                        $this->addVisibility($userId, $campo);
                    }

                    $updates[$campo] = $nuevoValor;
                }
            }

            if (!empty($updates)) {
                DB::table('perfiles')
                    ->where('usuario_id', $userId)
                    ->update($updates);
            }
        }

    /**
     * Hace la actualización de la tabla perfiles
     * - Si el perfil existe hace actualización incrementa
     * - Si no existe crea el perfil
     */

    private function updatePerfiles(int $userId, array $data): void
    {
        if (empty($data)) return;

        $perfil = DB::table('perfiles')
            ->where('usuario_id', $userId)
            ->first();

        DB::transaction(function () use ($userId, $perfil, $data) {

            if ($perfil) {
                $this->updatePerfilExistente($userId, $perfil, $data);
            } else {
                $this->crearPerfil($userId, $data);
            }
        });
    }

    /**
     * Actualiza el perfil completo del usuario.
     * - Separa los campos según su tabla destino (`usuarios` p `perfiles`).
     * - Ejecuta ambas actualizaciones dentro de una transacción.
     * - Retorna el perfil actualizado consolidado.
     */

    public function updateProfile(int $userId, array $data): array
    {
        $usuarios = [];
        $perfiles = [];

        foreach ($data as $campo => $valor) {
            if (in_array($campo, ['correo', 'telefono'])) {
                $usuarios[$campo] = $valor;
            }

            if (in_array($campo, ['biografia', 'ciudad', 'pais'])) {
                $perfiles[$campo] = $valor;
            }
        }

        DB::transaction(function () use ($userId, $usuarios, $perfiles) {

            if (!empty($usuarios)) {
                $this->updateUsuarios($userId, $usuarios);
            }

            if (!empty($perfiles)) {
                $this->updatePerfiles($userId, $perfiles);
            }
        });

        return $this->getProfile($userId);
    }
}





