<?php

namespace App\Services\api;

use Illuminate\Support\Facades\DB;
use App\Models\Usuario;
use App\Models\Perfil;
use App\Models\VisibilidadCampo;
use Illuminate\Support\Str;

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
        $usuario = Usuario::with(['perfil', 'visibilidades'])
            ->findOrFail($userId);

        $perfil = $usuario->perfil;

        $visibilidadRaw = $usuario->visibilidades
            ->pluck('visible', 'campo')
            ->toArray();
        
        return [
            'id' => $usuario->id_usuario,
            'nombre' => $usuario->nombre,
            'apellido' => $usuario->apellido,
            'correo' => $usuario->correo,
            'telefono' => $usuario->telefono,
            'profesion' => $perfil?->profesion,
            'biografia' => $perfil?->biografia,
            'ciudad' => $perfil?->ciudad,
            'pais' => $perfil?->pais,

            'visibilidad' => [
                'nombre' => true,
                'correo' => $visibilidadRaw['correo'] ?? false,
                'telefono' => $visibilidadRaw['telefono'] ?? false,
                'biografia' => $visibilidadRaw['biografia'] ?? false,
                'pais' => $visibilidadRaw['pais'] ?? false,
                'ciudad' => $visibilidadRaw['ciudad'] ?? false,
            ],
        ];
    }

    /**
     * Actualiza parcialmente los datos de la tabla `usuarios`
     */
    
    private function updateUsuarios(int $userId, array $data): void
    {
        if (empty($data)) return;

        $usuario = Usuario::findOrFail($userId);

        $usuario->update($data);
    }
        /**
     * Inserta la visibilidad de un campo específico (para datos nuevos)
     * Siempre establece el campo como visible = true
     */

        private function visibility(int $userId, string $campo): void
        {
            VisibilidadCampo::firstOrCreate(
                [
                    'usuario_id' => $userId,
                    'campo' => $campo,
                ],
                [
                    'visible' => DB::raw('true')
                ]
            );
        }
        /**
         * Crea un nuevo perfil para el usuario
         * - Inserta todos los campos recibidos
         * - Marca automáticamente como visibles todos los campos creados
         */
        private function crearPerfil(int $userId, array $data): void
        {
            // Crear perfil
            Perfil::create([
                'usuario_id' => $userId,
                ...$data
            ]);

            // Marcar visibilidad de cada campo recibido
            foreach ($data as $campo => $_) {
                $this->visibility($userId, $campo);
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
                        $this->visibility($userId, $campo);
                    }

                    $updates[$campo] = $nuevoValor;
                }
            }

            if (!empty($updates)) {
                $perfil->update($updates);
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

        DB::transaction(function () use ($userId, $data) {

            $perfil = Perfil::where('usuario_id', $userId)->first();

            if ($perfil) {
                $this->updatePerfilExistente($userId, $perfil, $data);
            } else {
                $this->crearPerfil($userId, $data);
            }
        });
    }

    /**
     * Actualiza el perfil completo del usuario
     * - Separa los campos según su tabla destino (`usuarios` p `perfiles`)
     * - Ejecuta ambas actualizaciones dentro de una transacción
     * - Retorna el perfil actualizado consolidado
     */

    public function updateProfile(int $userId, array $data): array
    {
        $usuarios = [];
        $perfiles = [];

        foreach ($data as $campo => $valor) {
            if (in_array($campo, ['correo', 'telefono','nombre','apellido'])) {
                $usuarios[$campo] = $valor;
            }

            if (in_array($campo, ['biografia', 'ciudad', 'pais','profesion'])) {
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

    
    /**
     * Actualiza múltiples campos de visibilidad de un usuario
     * Ejemplo: ['correo' => true, 'telefono' => false]
     */

    public function updateVisibility(int $userId, array $data): array
    {
        $rows = [];

        foreach ($data as $campo => $visible) {
            $bool = filter_var($visible, FILTER_VALIDATE_BOOLEAN);

            $rows[] = [
                'usuario_id' => $userId,
                'campo'      => $campo,
                'visible'    => DB::raw($bool ? 'true' : 'false'),
            ];
        }

        VisibilidadCampo::upsert(
            $rows,
            ['usuario_id', 'campo'],
            ['visible']
        );

        return $this->getProfile($userId);
    }

    /**
     * Seccion crud de imagenes de perfil---------------------------
     *
     */

    /**
     * Funcion para subir una imagen a Supabase Storage
     *
     */

        function uploadImage($file, string $carpeta)
    {
        $bucket = env('SUPABASE_BUCKET');
        $urlBase = env('SUPABASE_URL');
        $key = env('SUPABASE_KEY');

        $nombreArchivo = $carpeta . '/' . Str::uuid() . '.' . $file->getClientOriginalExtension();

        $fileContent = file_get_contents($file->getRealPath());

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $urlBase . '/storage/v1/object/' . $bucket . '/' . $nombreArchivo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $fileContent,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'Content-Type: ' . $file->getMimeType(),
            ],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('Error al subir imagen a Supabase: ' . $error);
        }

        return $urlBase . '/storage/v1/object/public/' . $bucket . '/' . $nombreArchivo;
    }

        function deleteImage(string $urlImagen)
    {
        $bucket = env('SUPABASE_BUCKET');
        $urlBase = env('SUPABASE_URL');
        $key = env('SUPABASE_KEY');

        // Extraer path del archivo desde la URL pública
        $path = str_replace($urlBase . '/storage/v1/object/public/' . $bucket . '/', '', $urlImagen);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $urlBase . '/storage/v1/object/' . $bucket . '/' . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
            ],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('Error eliminando archivo en Supabase: ' . $error);
        }

        return true;
    }

    /**
     * Funcion para agregar imagen de perfil o banner
     *
     */
    function addImageProfileBanner(int $usuarioId, $file, string $tipo)
    {
        DB::beginTransaction();

        try {
            $perfil = Perfil::where('usuario_id', $usuarioId)->first();

            if (!$perfil) {
                throw new \Exception('Perfil no encontrado');
            }

            if (!in_array($tipo, ['profile', 'banner'])) {
                throw new \Exception('Tipo inválido');
            }

            $carpeta = $tipo === 'profile' ? 'profile' : 'banner';

            $urlImagen = $this->uploadImage($file, $carpeta);

            if ($tipo === 'profile') {
                $perfil->foto_perfil = $urlImagen;
            } else {
                $perfil->foto_fondo = $urlImagen;
            }

            $perfil->fecha_modificacion = now();
            $perfil->save();

            DB::commit();

            return [
                'status' => true,
                'message' => 'Imagen actualizada correctamente',
                'url' => $urlImagen
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'status' => false,
                'message' => 'Error al actualizar imagen',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Funcion para eliminar imagen (perfil o banner)
     */
    function deleteProfileBannerImage(int $usuarioId, string $tipo)
    {
        DB::beginTransaction();

        try {
            $perfil = Perfil::where('usuario_id', $usuarioId)->first();

            if (!$perfil) {
                throw new \Exception('Perfil no encontrado');
            }

            if (!in_array($tipo, ['profile', 'banner'])) {
                throw new \Exception('Tipo inválido');
            }

            $urlImagen = null;

            if ($tipo === 'profile') {
                $urlImagen = $perfil->foto_perfil;
                $perfil->foto_perfil = null;
            } else {
                $urlImagen = $perfil->foto_fondo;
                $perfil->foto_fondo = null;
            }

            if ($urlImagen) {
                $this->deleteImage($urlImagen);
            }

            $perfil->fecha_modificacion = now();
            $perfil->save();

            DB::commit();

            return [
                'status' => true,
                'message' => 'Imagen eliminada correctamente'
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'status' => false,
                'message' => 'Error al eliminar imagen',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Funcion para actualizar imagen de perfil o banner
     *
     */

        function updateProfileBannerImage(int $usuarioId, $file, string $tipo)
    {
        DB::beginTransaction();

        try {
            $perfil = Perfil::where('usuario_id', $usuarioId)->first();

            if (!$perfil) {
                throw new \Exception('Perfil no encontrado');
            }

            if (!in_array($tipo, ['profile', 'banner'])) {
                throw new \Exception('Tipo inválido');
            }

            $urlAnterior = null;

            if ($tipo === 'profile') {
                $urlAnterior = $perfil->foto_perfil;
            } else {
                $urlAnterior = $perfil->foto_fondo;
            }

            $carpeta = $tipo === 'profile' ? 'profile' : 'banner';
            $urlNueva = $this->uploadImage($file, $carpeta);

            if ($urlAnterior) {
                try {
                    $this->deleteImage($urlAnterior);
                } catch (\Exception $e) {
                    // No detenemos el proceso si falla la eliminación
                }
            }

            if ($tipo === 'profile') {
                $perfil->foto_perfil = $urlNueva;
            } else {
                $perfil->foto_fondo = $urlNueva;
            }

            $perfil->fecha_modificacion = now();
            $perfil->save();

            DB::commit();

            return [
                'status' => true,
                'message' => 'Imagen actualizada correctamente',
                'url' => $urlNueva
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'status' => false,
                'message' => 'Error al actualizar imagen',
                'error' => $e->getMessage()
            ];
        }
    }
}





