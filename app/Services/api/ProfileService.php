<?php

namespace App\Services\api;

use Illuminate\Support\Facades\DB;
use App\Models\Usuario;
use App\Models\Perfil;
use App\Models\VisibilidadCampo;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Services\BitacoraService;

class ProfileService
{
    public function __construct(
        private readonly ProfileImageVariantService $imageVariants
    ) {
    }
 
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
        
        $fotoVariantes = $this->imageVariants->getVariantUrls($perfil?->foto_perfil);
        $bannerVariantes = $this->imageVariants->getBannerVariantUrls($perfil?->foto_fondo);

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
            'foto_perfil' => $perfil?->foto_perfil,
            'foto_perfil_medium_url' => $fotoVariantes['medium'] ?? null,
            'foto_perfil_small_url' => $fotoVariantes['small'] ?? null,
            'foto_perfil_thumb_url' => $fotoVariantes['thumb'] ?? null,
            'foto_fondo' => $perfil?->foto_fondo,
            'foto_fondo_medium_url' => $bannerVariantes['medium'] ?? null,
            'foto_fondo_small_url' => $bannerVariantes['small'] ?? null,
            'es_publico' => (bool) ($perfil?->es_publico ?? true),
            'portfolio_publico' => (bool) ($perfil?->es_publico ?? true),

            'visibilidad' => [
                'nombre' => true,
                'correo' => $visibilidadRaw['correo'] ?? false,
                'telefono' => $visibilidadRaw['telefono'] ?? false,
                'biografia' => $visibilidadRaw['biografia'] ?? false,
                'pais' => $visibilidadRaw['pais'] ?? false,
                'ciudad' => $visibilidadRaw['ciudad'] ?? false,
                'profesion' => $visibilidadRaw['profesion'] ?? false,
            ],
        ];
    }


    /**
     * Actualiza parcialmente los datos de la tabla `usuarios`
     */
    
    private function updateUsuario(int $userId, array $data): void
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
         * Actualiza un perfil existente.
         * - Solo actualiza campos cuyo valor haya cambiado
         * - Si un campo pasa de vacío/null a tener valor
         *   se activa automáticamente su visibilidad
         */

        private function createPerfil(int $userId): Perfil
    {
        return Perfil::create([
            'usuario_id' => $userId,
        ]);
    }

        private function updatePerfil(int $userId, array $data): void
        {
            $updates = [];

            $perfil = Perfil::where('usuario_id', $userId)->first();

            if (!$perfil) {
                $perfil = $this->createPerfil($userId);
            }

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
     * Actualiza el perfil completo del usuario
     * - Separa los campos según su tabla destino (`usuarios` p `perfiles`)
     * - Ejecuta ambas actualizaciones dentro de una transacción
     * - Retorna el perfil actualizado consolidado
     */

    public function updateProfile(int $userId, array $data): array
    {
        $datausuario = [];
        $dataperfil = [];

        foreach ($data as $campo => $valor) {
            if (in_array($campo, ['correo', 'telefono','nombre','apellido'])) {
                $datausuario[$campo] = $valor;
            }

            if (in_array($campo, ['biografia', 'ciudad', 'pais','profesion'])) {
                $dataperfil[$campo] = $valor;
            }
        }

        DB::transaction(function () use ($userId, $datausuario, $dataperfil) {

            if (!empty($datausuario)) {
                $this->updateUsuario($userId, $datausuario);
            }

            if (!empty($dataperfil)) {
                $this->updatePerfil($userId, $dataperfil);
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

    public function updatePortfolioVisibility(int $userId, bool $isPublic): array
    {
        $perfil = Perfil::where('usuario_id', $userId)->first();

        if (! $perfil) {
            DB::table('perfiles')->insert([
                'usuario_id' => $userId,
                'es_publico' => DB::raw($isPublic ? 'true' : 'false'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif ((bool) $perfil->es_publico !== $isPublic) {
            DB::table('perfiles')
                ->where('id_perfil', $perfil->id_perfil)
                ->update([
                    'es_publico' => DB::raw($isPublic ? 'true' : 'false'),
                    'updated_at' => now(),
                ]);
        }

        $perfil = Perfil::where('usuario_id', $userId)->firstOrFail();

        return [
            'user_id' => $userId,
            'portfolio_publico' => (bool) $perfil->es_publico,
            'es_publico' => (bool) $perfil->es_publico,
        ];
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
        $urlBase = rtrim((string) env('SUPABASE_URL'), '/');
        $key = env('SUPABASE_KEY');

        if (!$bucket || !$urlBase || !$key) {
            throw new \Exception('Configuración de Supabase incompleta. Verifica SUPABASE_URL, SUPABASE_BUCKET y SUPABASE_KEY.');
        }

        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $nombreArchivo = $carpeta . '/' . Str::uuid() . '.' . $file->getClientOriginalExtension();
        $fileContent = file_get_contents($file->getRealPath());

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $key,
                    'apikey' => $key,
                    'Content-Type' => $mimeType,
                ])
                ->withBody($fileContent, $mimeType)
                ->post($urlBase . '/storage/v1/object/' . $bucket . '/' . $nombreArchivo);
        } catch (\Throwable $exception) {
            throw new \Exception('Error al conectar con Supabase Storage: ' . $exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new \Exception(
                'Supabase rechazó la subida de imagen. HTTP ' . $response->status() . ' - ' . $response->body()
            );
        }

        return $urlBase . '/storage/v1/object/public/' . $bucket . '/' . $nombreArchivo;
    }

    /**
     * Funcion para eliminar una imagen de Supabase Storage
     *
     */


        function deleteImage(string $urlImagen)
    {
        $bucket = env('SUPABASE_BUCKET');
        $urlBase = rtrim((string) env('SUPABASE_URL'), '/');
        $key = env('SUPABASE_KEY');

        if (!$bucket || !$urlBase || !$key) {
            throw new \Exception('Configuración de Supabase incompleta. Verifica SUPABASE_URL, SUPABASE_BUCKET y SUPABASE_KEY.');
        }

        // Extraer path del archivo desde la URL pública de Supabase.
        $prefix = $urlBase . '/storage/v1/object/public/' . $bucket . '/';

        if (!str_starts_with($urlImagen, $prefix)) {
            Log::warning('No se eliminó la imagen porque no parece ser una URL pública de Supabase.', [
                'url' => $urlImagen,
            ]);

            return false;
        }

        $path = ltrim(str_replace($prefix, '', $urlImagen), '/');

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $key,
                    'apikey' => $key,
                ])
                ->delete($urlBase . '/storage/v1/object/' . $bucket . '/' . $path);
        } catch (\Throwable $exception) {
            throw new \Exception('Error al conectar con Supabase Storage para eliminar imagen: ' . $exception->getMessage(), 0, $exception);
        }

        if ($response->failed() && $response->status() !== 404) {
            throw new \Exception(
                'Supabase rechazó la eliminación de imagen. HTTP ' . $response->status() . ' - ' . $response->body()
            );
        }

        return true;
    }

    /**
     * Funcion para agregar imagen de perfil o banner
     *
     */
    function addImageProfileBanner(int $userId, $file, string $tipo)
    {
        DB::beginTransaction();

        try {
            $perfil = Perfil::where('usuario_id', $userId)->first();

            if (!$perfil) {
                throw new \Exception('Perfil no encontrado');
            }

            if (!in_array($tipo, ['profile', 'banner'])) {
                throw new \Exception('Tipo inválido');
            }

            $carpeta = $tipo === 'profile' ? 'profile' : 'banner';

            $urlImagen = $this->uploadImage($file, $carpeta);

            if ($tipo === 'profile') {
                $this->generateProfileVariantsSafely($file, $urlImagen);
                $perfil->foto_perfil = $urlImagen;
            } else {
                $this->generateBannerVariantsSafely($file, $urlImagen);
                $perfil->foto_fondo = $urlImagen;
            }

            $perfil->save();

            DB::commit();

            return [
                'status' => true,
                'message' => 'Imagen actualizada correctamente',
                'url' => $urlImagen,
                ...($tipo === 'profile' ? $this->variantResponse($urlImagen) : $this->bannerVariantResponse($urlImagen)),
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
    function deleteProfileBannerImage(int $userId, string $tipo)
    {
        $urlImagen = null;

        DB::beginTransaction();

        try {
            $perfil = Perfil::where('usuario_id', $userId)->first();

            if (!$perfil) {
                throw new \Exception('Perfil no encontrado');
            }

            if (!in_array($tipo, ['profile', 'banner'])) {
                throw new \Exception('Tipo inválido');
            }

            if ($tipo === 'profile') {
                $urlImagen = $perfil->foto_perfil;
                $perfil->foto_perfil = null;
            } else {
                $urlImagen = $perfil->foto_fondo;
                $perfil->foto_fondo = null;
            }

            $perfil->save();

            DB::commit();

            if ($urlImagen) {
                $this->deletePreviousProfileImageSafely($urlImagen, $tipo);
            }

            return [
                'status' => true,
                'message' => 'Imagen eliminada correctamente'
            ];

        } catch (\Throwable $e) {
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

        function updateProfileBannerImage(int $userId, $file, string $tipo)
    {
        $urlAnterior = null;
        $urlNueva = null;

        DB::beginTransaction();

        try {
            $perfil = Perfil::where('usuario_id', $userId)->first();

            if (!$perfil) {
                throw new \Exception('Perfil no encontrado');
            }

            if (!in_array($tipo, ['profile', 'banner'])) {
                throw new \Exception('Tipo inválido');
            }

            $urlAnterior = $tipo === 'profile'
                ? $perfil->foto_perfil
                : $perfil->foto_fondo;

            $carpeta = $tipo === 'profile' ? 'profile' : 'banner';
            $urlNueva = $this->uploadImage($file, $carpeta);

            if ($tipo === 'profile') {
                $this->generateProfileVariantsSafely($file, $urlNueva);
                $perfil->foto_perfil = $urlNueva;
            } else {
                $this->generateBannerVariantsSafely($file, $urlNueva);
                $perfil->foto_fondo = $urlNueva;
            }

            $perfil->save();

            DB::commit();

            if ($urlAnterior) {
                $this->deletePreviousProfileImageSafely($urlAnterior, $tipo);
            }

            return [
                'status' => true,
                'message' => 'Imagen actualizada correctamente',
                'url' => $urlNueva,
                ...($tipo === 'profile' ? $this->variantResponse($urlNueva) : $this->bannerVariantResponse($urlNueva)),
            ];

        } catch (\Throwable $e) {
            DB::rollBack();

            if ($urlNueva) {
                $this->deletePreviousProfileImageSafely($urlNueva, $tipo);
            }

            return [
                'status' => false,
                'message' => 'Error al actualizar imagen',
                'error' => $e->getMessage()
            ];
        }
    }

    private function deletePreviousProfileImageSafely(string $urlImagen, string $tipo): void
    {
        try {
            if ($tipo === 'profile') {
                $this->imageVariants->deleteVariants($urlImagen);
            } else {
                $this->imageVariants->deleteBannerVariants($urlImagen);
            }

            $this->deleteImage($urlImagen);
        } catch (\Throwable $e) {
            Log::warning('No se pudo eliminar la imagen anterior del perfil/banner.', [
                'tipo' => $tipo,
                'url' => $urlImagen,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function generateProfileVariantsSafely($file, string $originalUrl): void
    {
        try {
            $this->imageVariants->generateFromUploadedFile($file, $originalUrl);
        } catch (\Throwable $e) {
            Log::warning('No se pudieron generar variantes de foto de perfil.', [
                'url' => $originalUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function variantResponse(?string $originalUrl): array
    {
        $variants = $this->imageVariants->getVariantUrls($originalUrl);

        return [
            'foto_perfil_medium_url' => $variants['medium'] ?? null,
            'foto_perfil_small_url' => $variants['small'] ?? null,
            'foto_perfil_thumb_url' => $variants['thumb'] ?? null,
        ];
    }

    private function generateBannerVariantsSafely($file, string $originalUrl): void
    {
        try {
            $this->imageVariants->generateBannerFromUploadedFile($file, $originalUrl);
        } catch (\Throwable $e) {
            Log::warning('No se pudieron generar variantes del banner.', [
                'url' => $originalUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function bannerVariantResponse(?string $originalUrl): array
    {
        $variants = $this->imageVariants->getBannerVariantUrls($originalUrl);

        return [
            'foto_fondo_medium_url' => $variants['medium'] ?? null,
            'foto_fondo_small_url' => $variants['small'] ?? null,
        ];
    }
}

