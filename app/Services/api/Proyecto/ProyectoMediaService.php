<?php

namespace App\Services\api\Proyecto;

use App\Services\api\ProfileImageVariantService;
use App\Services\api\ProyectoNotificacionGuardadoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

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

    public function deleteImages(int $idProyecto, int $userId, array $urls): int
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
                if ($this->imageCandidateMatches((string) $candidate, $rowUrl, $rowPath)) {
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
            return 0;
        }

        DB::transaction(function () use ($idProyecto, $toDeleteIds) {
            DB::table('proyecto_evidencias')->whereIn('id_evidencia', $toDeleteIds)->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
            $this->ensureImageCover($idProyecto);
        });

        $this->proyectoNotificacionGuardadoService->notificarMaterialesProyectoActualizados(
            idProyecto: $idProyecto,
            idUsuarioActor: $userId,
            tipoMaterial: 'imágenes',
            accion: 'eliminado'
        );

        return count($toDeleteIds);
    }

    public function repairImageVariants(int $idProyecto, string $originalUrl): array
    {
        $row = DB::table('proyecto_evidencias')
            ->where('id_proyecto', $idProyecto)
            ->whereIn('tipo', [self::TIPO_IMAGEN, 'captura'])
            ->whereNull('deleted_at')
            ->where('url', $originalUrl)
            ->first();

        if (! $row) {
            return ['status' => 'not_found'];
        }

        if (! $this->profileImageVariants->originalExists($originalUrl)) {
            $this->profileImageVariants->deleteProjectVariants($originalUrl);
            DB::transaction(function () use ($idProyecto, $row) {
                DB::table('proyecto_evidencias')
                    ->where('id_evidencia', $row->id_evidencia)
                    ->update([
                        'deleted_at' => now(),
                        'updated_at' => now(),
                    ]);
                $this->ensureImageCover($idProyecto);
            });

            return ['status' => 'original_missing'];
        }

        return [
            'status' => 'repaired',
            'variants' => $this->profileImageVariants->generateProjectFromUrl($originalUrl),
        ];
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

    public function deleteDocuments(int $idProyecto, int $userId, array $urls): int
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
                    $this->deleteProjectFile($rowPath ?: null, $rowUrl);
                    $toDeleteIds[] = $row->id_evidencia;
                    break;
                }
            }
        }

        if ($toDeleteIds === []) {
            return 0;
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

        return count($toDeleteIds);
    }

    private function imageCandidateMatches(string $candidate, string $originalUrl, string $originalPath): bool
    {
        if ($candidate === $originalUrl) {
            return true;
        }

        $candidatePath = $this->normalizeStoragePathFromUrl($candidate);
        if ($candidatePath && $candidatePath === $originalPath) {
            return true;
        }

        return in_array($candidate, $this->profileImageVariants->getProjectVariantUrls($originalUrl), true);
    }

    private function ensureImageCover(int $idProyecto): void
    {
        $images = DB::table('proyecto_evidencias')
            ->where('id_proyecto', $idProyecto)
            ->whereIn('tipo', [self::TIPO_IMAGEN, 'captura'])
            ->whereNull('deleted_at')
            ->orderBy('orden')
            ->orderBy('id_evidencia')
            ->get(['id_evidencia', 'es_portada']);

        if ($images->isEmpty() || $images->contains(
            fn ($image) => filter_var($image->es_portada, FILTER_VALIDATE_BOOLEAN)
        )) {
            return;
        }

        DB::table('proyecto_evidencias')
            ->where('id_evidencia', $images->first()->id_evidencia)
            ->update(['es_portada' => DB::raw('TRUE'), 'updated_at' => now()]);
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
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $objectUrl = rtrim($urlBase, '/').'/storage/v1/object/'.$bucket.'/'.$path;

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$key,
                    'apikey' => $key,
                    'Content-Type' => $mimeType,
                ])
                ->withBody($content, $mimeType)
                ->post($objectUrl);
        } catch (\Throwable $exception) {
            Log::error('No se pudo subir archivo de proyecto a Supabase Storage.', [
                'folder' => $folder,
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            abort(502, 'Error al subir archivo a Supabase Storage.');
        }

        if ($response->failed()) {
            Log::error('Supabase rechazo la subida de archivo de proyecto.', [
                'folder' => $folder,
                'path' => $path,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            abort(502, 'Error al subir archivo a Supabase Storage.');
        }

        return [
            'path' => $path,
            'url' => rtrim($urlBase, '/').'/storage/v1/object/public/'.$bucket.'/'.$path,
            'response' => $response->body(),
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
            if (trim((string) $path) !== '') {
                throw new RuntimeException('Supabase Storage no esta configurado para eliminar el archivo original.');
            }

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

        $storagePath = ltrim($storagePath, '/');

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$key,
                    'apikey' => $key,
                ])
                ->delete(rtrim($urlBase, '/').'/storage/v1/object/'.$bucket.'/'.$storagePath);
        } catch (\Throwable $exception) {
            Log::warning('No se pudo eliminar archivo original de proyecto en Supabase Storage.', [
                'path' => $storagePath,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('No se pudo eliminar el archivo original de Supabase Storage.');
        }

        if ($response->status() === 404) {
            return;
        }

        if ($response->failed()) {
            $payload = json_decode($response->body(), true);
            $notFound = (int) ($payload['statusCode'] ?? 0) === 404;

            if ($notFound) {
                return;
            }

            Log::warning('Supabase rechazo la eliminacion de archivo original de proyecto.', [
                'path' => $storagePath,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new RuntimeException('No se pudo eliminar el archivo original de Supabase Storage.');
        }
    }
}
