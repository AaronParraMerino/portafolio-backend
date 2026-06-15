<?php

namespace App\Services\api\Proyecto;

use App\Services\api\ProfileImageVariantService;
use App\Services\api\ProyectoNotificacionGuardadoService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProyectoCicloVidaService
{
    public function __construct(
        private readonly ProyectoNotificacionGuardadoService $proyectoNotificacionGuardadoService,
        private readonly ProfileImageVariantService $profileImageVariants,
    ) {}

    public function deletionPreview(int $idProyecto): array
    {
        $project = DB::table('proyectos')
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->first(['id_proyecto', 'titulo']);

        if (! $project) {
            return ['status' => 'not_found'];
        }

        $repositoryCount = $this->activeRepositories($idProyecto)->count();
        $permanent = $repositoryCount === 0;

        return [
            'status' => 'success',
            'id_proyecto' => (int) $project->id_proyecto,
            'titulo' => (string) $project->titulo,
            'tipo_eliminacion' => $permanent ? 'permanente' : 'recuperable',
            'es_permanente' => $permanent,
            'repositorios_vinculados' => $repositoryCount,
            'requiere_confirmacion_reforzada' => $permanent,
            'texto_confirmacion' => $permanent ? (string) $project->titulo : null,
        ];
    }

    public function delete(int $userId, int $idProyecto, ?string $confirmationTitle = null): array
    {
        $preview = $this->deletionPreview($idProyecto);
        if (($preview['status'] ?? null) !== 'success') {
            return $preview;
        }

        if ($preview['es_permanente']) {
            if (! hash_equals($preview['titulo'], trim((string) $confirmationTitle))) {
                return [
                    ...$preview,
                    'status' => 'confirmation_required',
                    'message' => 'Escribe exactamente el titulo del proyecto para confirmar la eliminacion permanente.',
                ];
            }

            return $this->permanentlyDelete($userId, $idProyecto, $preview);
        }

        return $this->softDelete($userId, $idProyecto, $preview);
    }

    public function permanentlyDeleteDeletedProject(
        int $userId,
        int $idProyecto,
        ?string $confirmationTitle = null,
        string $action = 'eliminacion_permanente_ultimo_repositorio',
        ?int $repositoryBeingReleasedId = null
    ): array {
        $project = DB::table('proyectos')
            ->where('id_proyecto', $idProyecto)
            ->whereNotNull('deleted_at')
            ->first(['id_proyecto', 'titulo']);

        if (! $project) {
            return ['status' => 'not_found'];
        }

        $repositories = $this->activeRepositories($idProyecto);
        if ($repositoryBeingReleasedId !== null) {
            $repositories = $repositories->reject(
                fn ($repositoryId) => (int) $repositoryId === $repositoryBeingReleasedId
            );
        }
        $repositoryCount = $repositories->count();
        if ($repositoryCount > 0) {
            return ['status' => 'repositories_remain', 'repositorios_vinculados' => $repositoryCount];
        }

        if (! hash_equals((string) $project->titulo, trim((string) $confirmationTitle))) {
            return [
                'status' => 'confirmation_required',
                'tipo_eliminacion' => 'permanente',
                'titulo' => (string) $project->titulo,
                'message' => 'Escribe exactamente el titulo del proyecto para confirmar la eliminacion permanente.',
            ];
        }

        return $this->permanentlyDelete($userId, $idProyecto, [
            'titulo' => (string) $project->titulo,
            'audit_action' => $action,
        ]);
    }

    private function softDelete(int $userId, int $idProyecto, array $preview): array
    {
        $detachedParticipationIds = DB::transaction(function () use ($idProyecto, $userId, $preview) {
            $now = now();
            $ids = $this->unvalidatedParticipationIds($idProyecto);

            if ($ids->isNotEmpty()) {
                DB::table('participacion_repositorios')
                    ->whereIn('id_participacion', $ids)
                    ->delete();

                DB::table('participaciones')
                    ->whereIn('id_participacion', $ids)
                    ->update([
                        'estado_participacion' => 'retirado',
                        'deleted_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            DB::table('proyectos')->where('id_proyecto', $idProyecto)->update([
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);

            $this->recordAudit(
                $userId,
                $idProyecto,
                $preview['titulo'],
                'eliminacion_recuperable',
                (int) $preview['repositorios_vinculados'],
                ['participaciones_sin_validacion_desvinculadas' => $ids->count()]
            );

            return $ids;
        });

        $this->notifyProjectDeleted($idProyecto, $userId);

        return [
            'status' => 'soft_deleted',
            'tipo_eliminacion' => 'recuperable',
            'participaciones_sin_validacion_desvinculadas' => $detachedParticipationIds->count(),
        ];
    }

    private function permanentlyDelete(int $userId, int $idProyecto, array $preview): array
    {
        $evidences = DB::table('proyecto_evidencias')
            ->where('id_proyecto', $idProyecto)
            ->get(['tipo', 'url', 'archivo_path']);

        $this->notifyProjectDeleted($idProyecto, $userId);

        DB::transaction(function () use ($idProyecto, $userId, $preview) {
            $this->recordAudit(
                $userId,
                $idProyecto,
                $preview['titulo'],
                $preview['audit_action'] ?? 'eliminacion_permanente_sin_repositorios',
                0,
                ['motivo' => 'Proyecto sin repositorios vinculados']
            );

            DB::table('proyectos')->where('id_proyecto', $idProyecto)->delete();
        });

        $this->deleteEvidenceFiles($evidences);

        return [
            'status' => 'permanently_deleted',
            'tipo_eliminacion' => 'permanente',
        ];
    }

    private function unvalidatedParticipationIds(int $idProyecto): Collection
    {
        return DB::table('participaciones as p')
            ->where('p.id_proyecto', $idProyecto)
            ->whereNull('p.deleted_at')
            ->whereRaw('COALESCE(p.es_propietario, FALSE) = FALSE')
            ->whereRaw('COALESCE(p.participacion_validada, FALSE) = FALSE')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('participacion_repositorios as pr')
                    ->whereColumn('pr.id_participacion', 'p.id_participacion')
                    ->whereRaw('COALESCE(pr.validado, FALSE) = TRUE');
            })
            ->pluck('p.id_participacion');
    }

    private function activeRepositories(int $idProyecto): Collection
    {
        return DB::table('proyecto_repositorios')
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->pluck('id_proyecto_repositorio');
    }

    private function recordAudit(
        int $userId,
        int $idProyecto,
        string $title,
        string $action,
        int $repositoryCount,
        array $metadata = []
    ): void {
        DB::table('bitacoras')->insert([
            'usuario_id' => $userId,
            'usuario_referencia_id' => $userId,
            'accion' => $action,
            'descripcion' => json_encode([
                'titulo_proyecto' => $title,
                'repositorios_afectados' => $repositoryCount,
                ...$metadata,
            ], JSON_UNESCAPED_UNICODE),
            'tabla_afectada' => 'proyectos',
            'registro_afectado_id' => $idProyecto,
            'fecha' => now(),
        ]);
    }

    private function notifyProjectDeleted(int $idProyecto, int $userId): void
    {
        try {
            $this->proyectoNotificacionGuardadoService->notificarProyectoEliminado(
                idProyecto: $idProyecto,
                idUsuarioActor: $userId
            );
        } catch (\Throwable $exception) {
            Log::warning('No se pudo notificar la eliminacion de un proyecto.', [
                'id_proyecto' => $idProyecto,
                'id_usuario_actor' => $userId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function deleteEvidenceFiles(Collection $evidences): void
    {
        foreach ($evidences as $evidence) {
            $url = trim((string) ($evidence->url ?? ''));
            $path = trim((string) ($evidence->archivo_path ?? ''));

            if (in_array($evidence->tipo ?? null, ['imagen', 'captura'], true) && $url !== '') {
                try {
                    $this->profileImageVariants->deleteProjectVariants($url);
                } catch (\Throwable $exception) {
                    Log::warning('No se pudieron eliminar variantes de un proyecto eliminado permanentemente.', [
                        'url' => $url,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $storagePath = $path !== '' ? $path : $this->storagePathFromUrl($url);
            if ($storagePath !== '') {
                $this->deleteSupabaseFile($storagePath);
            }
        }
    }

    private function storagePathFromUrl(string $url): string
    {
        $bucket = env('SUPABASE_BUCKET');
        $urlBase = env('SUPABASE_URL');

        if (! $bucket || ! $urlBase || $url === '') {
            return '';
        }

        $prefix = rtrim($urlBase, '/').'/storage/v1/object/public/'.$bucket.'/';

        return str_starts_with($url, $prefix)
            ? (string) substr($url, strlen($prefix))
            : '';
    }

    private function deleteSupabaseFile(string $path): void
    {
        $bucket = env('SUPABASE_BUCKET');
        $urlBase = env('SUPABASE_URL');
        $key = env('SUPABASE_KEY');

        if (! $bucket || ! $urlBase || ! $key) {
            return;
        }

        $normalizedPath = ltrim($path, '/');

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$key,
                    'apikey' => $key,
                ])
                ->delete(rtrim($urlBase, '/').'/storage/v1/object/'.$bucket.'/'.$normalizedPath);

            if ($response->failed() && $response->status() !== 404) {
                Log::warning('No se pudo eliminar un archivo de Supabase al borrar definitivamente un proyecto.', [
                    'path' => $normalizedPath,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('No se pudo eliminar un archivo de Supabase al borrar definitivamente un proyecto.', [
                'path' => $normalizedPath,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
