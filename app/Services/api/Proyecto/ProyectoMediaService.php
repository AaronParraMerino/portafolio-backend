<?php

namespace App\Services\api\Proyecto;

use App\Services\api\ProfileImageVariantService;
use App\Services\api\ProyectoNotificacionGuardadoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProyectoMediaService
{
    private const TIPO_IMAGEN = 'imagen';

    private const TIPO_DOCUMENTO = 'documento';

    public function __construct(
        private readonly ProfileImageVariantService $profileImageVariants,
        private readonly ProyectoNotificacionGuardadoService $proyectoNotificacionGuardadoService,
    ) {}

    public function uploadImages(int $idProyecto, int $userId, array $files): array
    {
        $saved = [];
        $baseOrder = (int) DB::table('proyecto_evidencias')
            ->where('id_proyecto', $idProyecto)
            ->whereIn('tipo', [self::TIPO_IMAGEN, 'captura'])
            ->whereNull('deleted_at')
            ->max('orden');

        foreach ($files as $index => $file) {
            $upload = $this->uploadProjectFileToSupabase($file, "projects/{$idProyecto}/images");
            $path = $upload['path'];
            $url = $upload['url'];
            $this->generateProjectVariantsSafely($file, $url);

            DB::table('proyecto_evidencias')->insert([
                'id_proyecto' => $idProyecto,
                'titulo' => $file->getClientOriginalName() ?: ('Imagen '.($index + 1)),
                'descripcion' => null,
                'tipo' => self::TIPO_IMAGEN,
                'url' => $url,
                'archivo_path' => $path,
                'mime_type' => $file->getMimeType(),
                'tamanio_bytes' => $file->getSize(),
                'es_portada' => ($index === 0 && $baseOrder <= 0) ? 'true' : 'false',
                'es_visible' => 'true',
                'orden' => $baseOrder + $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $saved[] = $url;
        }

        if ($saved !== []) {
            $this->proyectoNotificacionGuardadoService->notificarMaterialesProyectoActualizados(
                idProyecto: $idProyecto,
                idUsuarioActor: $userId,
                tipoMaterial: 'imágenes',
                accion: 'agregado'
            );
        }

        return $saved;
    }

    public function deleteImages(int $idProyecto, int $userId, array $urls): bool
    {
        $rows = DB::table('proyecto_evidencias')
            ->where('id_proyecto', $idProyecto)
            ->whereIn('tipo', [self::TIPO_IMAGEN, 'captura'])
            ->whereNull('deleted_at')
            ->get();

        $toDeleteIds = [];
        foreach ($rows as $row) {
            $rowUrl = (string) ($row->url ?? '');
            $rowPath = (string) ($row->archivo_path ?? '');

            foreach ($urls as $candidate) {
                $normalizedCandidatePath = $this->normalizeStoragePathFromUrl((string) $candidate);
                if ($candidate === $rowUrl || ($normalizedCandidatePath && $normalizedCandidatePath === $rowPath)) {
                    if ($rowUrl) {
                        $this->profileImageVariants->deleteProjectVariants($rowUrl);
                    }
                    $this->deleteProjectFile($rowPath ?: null, $rowUrl);
                    $toDeleteIds[] = $row->id_evidencia;
                    break;
                }
            }
        }

        if ($toDeleteIds === []) {
            return false;
        }

        DB::table('proyecto_evidencias')->whereIn('id_evidencia', $toDeleteIds)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        $this->proyectoNotificacionGuardadoService->notificarMaterialesProyectoActualizados(
            idProyecto: $idProyecto,
            idUsuarioActor: $userId,
            tipoMaterial: 'imágenes',
            accion: 'eliminado'
        );

        return true;
    }

    public function uploadDocuments(int $idProyecto, int $userId, array $files): array
    {
        $docs = [];
        $baseOrder = (int) DB::table('proyecto_evidencias')
            ->where('id_proyecto', $idProyecto)
            ->whereIn('tipo', [self::TIPO_DOCUMENTO, 'pdf', 'documentacion', 'presentacion'])
            ->whereNull('deleted_at')
            ->max('orden');

        foreach ($files as $index => $file) {
            $upload = $this->uploadProjectFileToSupabase($file, "projects/{$idProyecto}/documents");
            $path = $upload['path'];
            $url = $upload['url'];
            $mime = $file->getMimeType();
            $isPdf = Str::contains((string) $mime, 'pdf');

            DB::table('proyecto_evidencias')->insert([
                'id_proyecto' => $idProyecto,
                'titulo' => $file->getClientOriginalName() ?: ('Documento '.($index + 1)),
                'descripcion' => null,
                'tipo' => $isPdf ? 'pdf' : self::TIPO_DOCUMENTO,
                'url' => $url,
                'archivo_path' => $path,
                'mime_type' => $mime,
                'tamanio_bytes' => $file->getSize(),
                'es_portada' => 'false',
                'es_visible' => 'true',
                'orden' => $baseOrder + $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $docs[] = [
                'url' => $url,
                'nombre' => $file->getClientOriginalName(),
                'mime_type' => $mime,
                'size' => $file->getSize(),
            ];
        }

        if ($docs !== []) {
            $this->proyectoNotificacionGuardadoService->notificarMaterialesProyectoActualizados(
                idProyecto: $idProyecto,
                idUsuarioActor: $userId,
                tipoMaterial: 'documentos',
                accion: 'agregado'
            );
        }

        return $docs;
    }

    public function deleteDocuments(int $idProyecto, int $userId, array $urls): bool
    {
        $rows = DB::table('proyecto_evidencias')
            ->where('id_proyecto', $idProyecto)
            ->whereIn('tipo', [self::TIPO_DOCUMENTO, 'pdf', 'documentacion', 'presentacion'])
            ->whereNull('deleted_at')
            ->get();

        $toDeleteIds = [];
        foreach ($rows as $row) {
            $rowUrl = (string) ($row->url ?? '');
            $rowPath = (string) ($row->archivo_path ?? '');

            foreach ($urls as $candidate) {
                $normalizedCandidatePath = $this->normalizeStoragePathFromUrl((string) $candidate);
                if ($candidate === $rowUrl || ($normalizedCandidatePath && $normalizedCandidatePath === $rowPath)) {
                    if ($rowPath) {
                        $this->deleteProjectFile($rowPath, $rowUrl);
                    }
                    $toDeleteIds[] = $row->id_evidencia;
                    break;
                }
            }
        }

        if ($toDeleteIds === []) {
            return false;
        }

        DB::table('proyecto_evidencias')->whereIn('id_evidencia', $toDeleteIds)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        $this->proyectoNotificacionGuardadoService->notificarMaterialesProyectoActualizados(
            idProyecto: $idProyecto,
            idUsuarioActor: $userId,
            tipoMaterial: 'documentos',
            accion: 'eliminado'
        );

        return true;
    }

    private function normalizeStoragePathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        if (str_starts_with($path, '/storage/')) {
            return ltrim(Str::after($path, '/storage/'), '/');
        }

        return ltrim($path, '/');
    }

    private function uploadProjectFileToSupabase($file, string $folder): array
    {
        $bucket = env('SUPABASE_BUCKET');
        $urlBase = env('SUPABASE_URL');
        $key = env('SUPABASE_KEY');

        if (! $bucket || ! $urlBase || ! $key) {
            abort(500, 'Supabase Storage no esta configurado.');
        }

        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $path = trim($folder, '/').'/'.Str::uuid().'.'.$extension;
        $content = file_get_contents($file->getRealPath());

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($urlBase, '/').'/storage/v1/object/'.$bucket.'/'.$path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$key,
                'apikey: '.$key,
                'Content-Type: '.($file->getMimeType() ?: 'application/octet-stream'),
            ],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || $status >= 400) {
            abort(502, 'Error al subir archivo a Supabase Storage.');
        }

        return [
            'path' => $path,
            'url' => rtrim($urlBase, '/').'/storage/v1/object/public/'.$bucket.'/'.$path,
            'response' => $response,
        ];
    }

    private function generateProjectVariantsSafely($file, string $originalUrl): void
    {
        try {
            $this->profileImageVariants->generateProjectFromUploadedFile($file, $originalUrl);
        } catch (\Throwable $e) {
            Log::warning('No se pudieron generar variantes de imagen de proyecto.', [
                'url' => $originalUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function deleteProjectFile(?string $path, ?string $url = null): void
    {
        $bucket = env('SUPABASE_BUCKET');
        $urlBase = env('SUPABASE_URL');
        $key = env('SUPABASE_KEY');

        if (! $bucket || ! $urlBase || ! $key) {
            return;
        }

        $storagePath = trim((string) $path);

        if ($storagePath === '' && $url) {
            $prefix = rtrim($urlBase, '/').'/storage/v1/object/public/'.$bucket.'/';
            $storagePath = str_starts_with($url, $prefix)
                ? substr($url, strlen($prefix))
                : '';
        }

        if ($storagePath === '') {
            return;
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($urlBase, '/').'/storage/v1/object/'.$bucket.'/'.ltrim($storagePath, '/'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$key,
                'apikey: '.$key,
            ],
        ]);

        curl_exec($ch);
        curl_close($ch);
    }
}
