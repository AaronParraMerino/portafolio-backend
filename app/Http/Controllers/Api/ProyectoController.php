<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\GithubRepositorySyncService;
use App\Services\api\ProfileImageVariantService;
use App\Services\api\TecnologiaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProyectoController extends Controller
{
    private const TIPO_REPOS = 'repositorio';
    private const TIPO_DEMO = 'demo';
    private const TIPO_VIDEO = 'video';
    private const TIPO_IMAGEN = 'imagen';
    private const TIPO_DOCUMENTO = 'documento';

    public function __construct(
        private readonly GithubRepositorySyncService $githubRepositorySyncService,
        private readonly TecnologiaService $tecnologiaService,
        private readonly ProfileImageVariantService $profileImageVariants,
    ) {
    }

    public function indexByUsuario(Request $request, int $userId): JsonResponse
    {
        $authUserId = (int) ($request->user()->id_usuario ?? 0);
        if ($authUserId <= 0 || $authUserId !== $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $proyectos = DB::table('participaciones as p')
            ->join('proyectos as pr', 'pr.id_proyecto', '=', 'p.id_proyecto')
            ->where('p.id_usuario', $userId)
            ->whereNull('p.deleted_at')
            ->whereNull('pr.deleted_at')
            ->orderByDesc('pr.updated_at')
            ->select(
                'pr.*',
                'p.id_participacion',
                'p.id_usuario as participacion_id_usuario',
                'p.rol',
                'p.descripcion_aporte',
                'p.es_propietario',
                'p.participacion_validada',
                'p.visibilidad',
                'p.fecha_inicio as part_fecha_inicio',
                'p.fecha_fin as part_fecha_fin'
            )
            ->get();

        $indexContext = $this->loadProjectIndexContext($proyectos, $userId);
        $data = $proyectos->map(function ($row) use ($indexContext) {
            return $this->serializeProject((array) $row, $indexContext);
        })->values();

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $project = $this->findProjectForUser((int) ($request->user()->id_usuario ?? 0), $id);
        if (!$project) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        return response()->json(['data' => $this->serializeProject($project)]);
    }

    public function participants(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);
        $project = $this->findProjectForUser($userId, $id);

        if (!$project) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id_proyecto' => $id,
                'participantes' => $this->collectProjectParticipants($id, $userId),
            ],
        ]);
    }

    public function configuration(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);
        $project = $this->findProjectForUser($userId, $id);

        if (!$project) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        $permissions = $this->resolveProjectPermissions($userId, $id);
        if (! $permissions['puede_configurar']) {
            return response()->json(['message' => 'No tienes permiso para configurar este proyecto'], 403);
        }

        return response()->json([
            'data' => [
                'configuracion' => $this->getProjectConfiguration($id),
                'permisos' => $permissions,
            ],
        ]);
    }

    public function updateConfiguration(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);
        $project = $this->findProjectForUser($userId, $id);

        if (!$project) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        $permissions = $this->resolveProjectPermissions($userId, $id);
        if (! $permissions['puede_configurar']) {
            return response()->json(['message' => 'No tienes permiso para configurar este proyecto'], 403);
        }

        $payload = $request->validate([
            'permitir_participantes_sin_validacion' => 'sometimes|boolean',
            'puede_editar_proyecto' => 'sometimes|in:propietarios,autoridad_github,participantes_validados,participantes',
            'puede_administrar_proyecto' => 'sometimes|in:propietarios,autoridad_github',
            'github_nivel_autoridad' => 'sometimes|in:owner,maintainer,admin_push',
            'github_prevalece_sobre_creador' => 'sometimes|boolean',
            'visibilidad_usuario_sin_validacion' => 'sometimes|in:oculto,visible',
            'permitir_remover_participantes_sin_validacion' => 'sometimes|boolean',
        ]);

        foreach ([
            'permitir_participantes_sin_validacion',
            'github_prevalece_sobre_creador',
            'permitir_remover_participantes_sin_validacion',
        ] as $booleanField) {
            if (array_key_exists($booleanField, $payload)) {
                $payload[$booleanField] = $this->postgresBool($payload[$booleanField]);
            }
        }

        $payload['updated_at'] = now();

        DB::table('proyecto_configuraciones')
            ->where('id_proyecto', $id)
            ->update($payload);

        return response()->json([
            'message' => 'Configuracion actualizada correctamente',
            'data' => [
                'configuracion' => $this->getProjectConfiguration($id),
                'permisos' => $this->resolveProjectPermissions($userId, $id),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);
        if ($userId <= 0) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $payload = $this->validatePayload($request);
        $existingProject = $this->githubRepositorySyncService->findExistingProjectForJoinableRepoUrls(
            $userId,
            $payload['url_repositorios'] ?? [],
        );

        if (($existingProject['status'] ?? null) === 'repo_requires_validation') {
            return response()->json($existingProject, $existingProject['http_status'] ?? 403);
        }

        if (($existingProject['status'] ?? null) === 'success') {
            $existingProjectId = (int) $existingProject['id_proyecto'];
            $this->githubRepositorySyncService->linkUsuarioToExistingProjectByRepo($userId, $existingProjectId, [
                'rol' => $payload['rol'] ?? null,
                'descripcion_aporte' => $payload['descripcion_aporte'] ?? null,
            ]);

            $project = $this->findProjectForUser($userId, $existingProjectId);
            return response()->json([
                'message' => 'Este repositorio ya tenia un proyecto vinculado; se agrego tu participacion al proyecto existente.',
                'data' => $this->serializeProject($project),
                'linked_existing_project' => true,
            ], 200);
        }

        $idProyecto = DB::transaction(function () use ($payload, $userId) {
            $now = now();
            $estadoPublicacion = $this->mapEstadoPublicacion($payload['estado_publicacion'] ?? $payload['estado']);
            $estadoDesarrollo = $this->mapEstadoDesarrollo($payload['estado_desarrollo'] ?? $payload['estado']);

            $idProyecto = DB::table('proyectos')->insertGetId([
                'titulo' => $payload['titulo'],
                'descripcion' => $payload['descripcion'] ?? null,
                'plataforma_objetivo' => $this->mapPlataforma($payload['desarrollado_para'] ?? null),
                'categoria_proyecto' => $this->mapCategoria($payload['tipo'] ?? null),
                'estado_publicacion' => $estadoPublicacion,
                'estado_desarrollo' => $estadoDesarrollo,
                'fecha_inicio' => $payload['fecha_inicio'] ?? null,
                'fecha_fin' => $payload['fecha_fin'] ?? null,
                'origen' => 'manual',
                'es_destacado' => 'false',
                'orden' => 0,
                'publicado_at' => $estadoPublicacion === 'publicado' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_proyecto');

            DB::table('participaciones')->insert([
                'id_usuario' => $userId,
                'id_proyecto' => $idProyecto,
                'rol' => $payload['rol'] ?? null,
                'descripcion_aporte' => $payload['descripcion_aporte'] ?? null,
                'es_propietario' => 'true',
                'visibilidad' => ($payload['es_publico'] ?? true) ? 'publico' : 'privado',
                'estado_participacion' => 'activo',
                'fecha_inicio' => $payload['fecha_inicio'] ?? null,
                'fecha_fin' => $payload['fecha_fin'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('proyecto_configuraciones')->insertOrIgnore([
                'id_proyecto' => $idProyecto,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return (int) $idProyecto;
        });

        $this->syncLinkEvidences($idProyecto, $payload);
        $this->syncProjectRepositories($userId, $idProyecto, $payload);
        $this->syncProjectTechnologies($userId, $idProyecto, $payload);

        $project = $this->findProjectForUser($userId, $idProyecto);
        return response()->json(['data' => $this->serializeProject($project)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);
        $project = $this->findProjectForUser($userId, $id);
        if (!$project) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        if (! $this->resolveProjectPermissions($userId, $id)['puede_editar']) {
            return response()->json(['message' => 'No tienes permiso para editar este proyecto'], 403);
        }

        $payload = $this->validatePayload($request, true);

        DB::transaction(function () use ($payload, $id, $userId, $project) {
            $updateProject = [];
            foreach (['titulo', 'descripcion', 'fecha_inicio', 'fecha_fin'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $updateProject[$field] = $payload[$field];
                }
            }

            if (array_key_exists('desarrollado_para', $payload)) {
                $updateProject['plataforma_objetivo'] = $this->mapPlataforma($payload['desarrollado_para']);
            }

            if (array_key_exists('tipo', $payload)) {
                $updateProject['categoria_proyecto'] = $this->mapCategoria($payload['tipo']);
            }

            if (array_key_exists('estado_publicacion', $payload) || array_key_exists('estado', $payload)) {
                $updateProject['estado_publicacion'] = $this->mapEstadoPublicacion($payload['estado_publicacion'] ?? $payload['estado']);

                if ($updateProject['estado_publicacion'] === 'publicado' && empty($project['publicado_at'])) {
                    $updateProject['publicado_at'] = now();
                } elseif ($updateProject['estado_publicacion'] !== 'publicado') {
                    $updateProject['publicado_at'] = null;
                }
            }

            if (array_key_exists('estado_desarrollo', $payload) || array_key_exists('estado', $payload)) {
                $updateProject['estado_desarrollo'] = $this->mapEstadoDesarrollo($payload['estado_desarrollo'] ?? $payload['estado']);
            }

            if (!empty($updateProject)) {
                $updateProject['updated_at'] = now();
                DB::table('proyectos')->where('id_proyecto', $id)->update($updateProject);
            }

            $updateParticipacion = [];
            if (array_key_exists('rol', $payload)) {
                $updateParticipacion['rol'] = $payload['rol'];
            }
            if (array_key_exists('descripcion_aporte', $payload)) {
                $updateParticipacion['descripcion_aporte'] = $payload['descripcion_aporte'];
            }
            if (array_key_exists('es_publico', $payload)) {
                $updateParticipacion['visibilidad'] = $payload['es_publico'] ? 'publico' : 'privado';
            }
            if (array_key_exists('fecha_inicio', $payload)) {
                $updateParticipacion['fecha_inicio'] = $payload['fecha_inicio'];
            }
            if (array_key_exists('fecha_fin', $payload)) {
                $updateParticipacion['fecha_fin'] = $payload['fecha_fin'];
            }

            if (!empty($updateParticipacion)) {
                $updateParticipacion['updated_at'] = now();
                DB::table('participaciones')
                    ->where('id_proyecto', $id)
                    ->where('id_usuario', $userId)
                    ->whereNull('deleted_at')
                    ->update($updateParticipacion);
            }

        });

        $this->syncLinkEvidences($id, $payload);
        $this->syncProjectRepositories($userId, $id, $payload);
        $this->syncProjectTechnologies($userId, $id, $payload);

        $updated = $this->findProjectForUser($userId, $id);
        return response()->json(['data' => $this->serializeProject($updated)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);
        $project = $this->findProjectForUser($userId, $id);
        if (!$project) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        if (! $this->resolveProjectPermissions($userId, $id)['puede_eliminar']) {
            return response()->json(['message' => 'No tienes permiso para eliminar este proyecto'], 403);
        }

        DB::table('proyectos')->where('id_proyecto', $id)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Proyecto eliminado correctamente']);
    }

    public function detachParticipation(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);
        $participacion = DB::table('participaciones')
            ->where('id_usuario', $userId)
            ->where('id_proyecto', $id)
            ->whereNull('deleted_at')
            ->first();

        if (! $participacion) {
            return response()->json(['message' => 'Participacion no encontrada'], 404);
        }

        $participantesActivos = $this->activeProjectParticipantsCount($id);

        if ($participantesActivos <= 1) {
            return response()->json([
                'message' => 'No puedes desvincularte porque eres el unico participante del proyecto. Puedes eliminar el proyecto.',
            ], 422);
        }

        if ($this->isSoleActiveProjectOwner($participacion, $id)) {
            return response()->json([
                'message' => 'No puedes desvincularte porque eres el propietario principal del proyecto. Elimina el proyecto o asigna otro propietario antes de salir.',
            ], 422);
        }

        $permissions = $this->resolveProjectPermissions($userId, $id);
        if (! $permissions['puede_desvincular_participacion']) {
            return response()->json(['message' => 'No tienes permiso para desvincular esta participacion'], 403);
        }

        DB::transaction(function () use ($participacion) {
            DB::table('participacion_repositorios')
                ->where('id_participacion', $participacion->id_participacion)
                ->delete();

            DB::table('participaciones')
                ->where('id_participacion', $participacion->id_participacion)
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return response()->json(['message' => 'Participacion desvinculada correctamente']);
    }

    public function removeParticipant(Request $request, int $id, int $participacionId): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);
        $permissions = $this->resolveProjectPermissions($userId, $id);

        if (! ($permissions['puede_remover_participantes_sin_validacion'] ?? false)) {
            return response()->json(['message' => 'No tienes permiso para quitar participantes sin validacion'], 403);
        }

        $participacion = DB::table('participaciones')
            ->where('id_participacion', $participacionId)
            ->where('id_proyecto', $id)
            ->whereNull('deleted_at')
            ->first();

        if (! $participacion) {
            return response()->json(['message' => 'Participacion no encontrada'], 404);
        }

        if ((int) ($participacion->id_usuario ?? 0) === $userId) {
            return response()->json(['message' => 'Usa la opcion de desvincular tu propia participacion.'], 422);
        }

        if ($this->truthy($participacion->es_propietario ?? false)) {
            return response()->json(['message' => 'No puedes quitar al propietario del proyecto desde esta opcion.'], 422);
        }

        $isValidated = (bool) ($participacion->participacion_validada ?? false)
            || $this->hasValidatedGithubParticipation((int) $participacion->id_usuario, $id);

        if ($isValidated) {
            return response()->json(['message' => 'Solo se pueden quitar participantes sin validacion GitHub desde esta opcion.'], 422);
        }

        $participantesActivos = $this->activeProjectParticipantsCount($id);

        if ($participantesActivos <= 1) {
            return response()->json(['message' => 'No puedes quitar al unico participante del proyecto.'], 422);
        }

        DB::transaction(function () use ($participacionId) {
            DB::table('participacion_repositorios')
                ->where('id_participacion', $participacionId)
                ->delete();

            DB::table('participaciones')
                ->where('id_participacion', $participacionId)
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return response()->json(['message' => 'Participacion sin validacion quitada correctamente']);
    }

    public function uploadImages(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);
        if (!$this->findProjectForUser($userId, $id)) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        if (! $this->resolveProjectPermissions($userId, $id)['puede_editar']) {
            return response()->json(['message' => 'No tienes permiso para editar este proyecto'], 403);
        }

        $request->validate([
            'images.*' => 'sometimes|file|image|max:2048',
            'imagenes.*' => 'sometimes|file|image|max:2048',
        ], [
            'images.*.uploaded' => 'No se puede subir archivos mayores a 2 MB.',
            'imagenes.*.uploaded' => 'No se puede subir archivos mayores a 2 MB.',
            'images.*.max' => 'No se puede subir archivos mayores a 2 MB.',
            'imagenes.*.max' => 'No se puede subir archivos mayores a 2 MB.',
        ]);

        $files = $request->file('images', []);
        if (empty($files)) {
            $files = $request->file('imagenes', []);
        }

        $saved = [];
        $baseOrder = (int) DB::table('proyecto_evidencias')
            ->where('id_proyecto', $id)
            ->whereIn('tipo', [self::TIPO_IMAGEN, 'captura'])
            ->whereNull('deleted_at')
            ->max('orden');

        foreach ($files as $index => $file) {
            $upload = $this->uploadProjectFileToSupabase($file, "projects/{$id}/images");
            $path = $upload['path'];
            $url = $upload['url'];
            $this->generateProjectVariantsSafely($file, $url);

            DB::table('proyecto_evidencias')->insert([
                'id_proyecto' => $id,
                'titulo' => $file->getClientOriginalName() ?: ('Imagen ' . ($index + 1)),
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

        return response()->json(['urls' => $saved, 'imagenes' => $saved]);
    }

    public function deleteImages(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);
        if (!$this->findProjectForUser($userId, $id)) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        if (! $this->resolveProjectPermissions($userId, $id)['puede_editar']) {
            return response()->json(['message' => 'No tienes permiso para editar este proyecto'], 403);
        }

        $urls = collect($request->input('urls', $request->input('imagenes', [])))
            ->filter(fn ($u) => is_string($u) && trim($u) !== '')
            ->values();

        if ($urls->isEmpty()) {
            return response()->json(['message' => 'Sin imágenes para eliminar']);
        }

        $rows = DB::table('proyecto_evidencias')
            ->where('id_proyecto', $id)
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

        if (!empty($toDeleteIds)) {
            DB::table('proyecto_evidencias')->whereIn('id_evidencia', $toDeleteIds)->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Imágenes eliminadas correctamente']);
    }

    public function reorderImages(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'Reordenado no implementado', 'ok' => true]);
    }

    public function uploadDocuments(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);
        if (!$this->findProjectForUser($userId, $id)) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        if (! $this->resolveProjectPermissions($userId, $id)['puede_editar']) {
            return response()->json(['message' => 'No tienes permiso para editar este proyecto'], 403);
        }

        $request->validate([
            'documents.*' => 'sometimes|file|max:2048',
            'documentos.*' => 'sometimes|file|max:2048',
        ], [
            'documents.*.uploaded' => 'No se puede subir archivos mayores a 2 MB.',
            'documentos.*.uploaded' => 'No se puede subir archivos mayores a 2 MB.',
            'documents.*.max' => 'No se puede subir archivos mayores a 2 MB.',
            'documentos.*.max' => 'No se puede subir archivos mayores a 2 MB.',
        ]);

        $files = $request->file('documents', []);
        if (empty($files)) {
            $files = $request->file('documentos', []);
        }

        $docs = [];
        $baseOrder = (int) DB::table('proyecto_evidencias')
            ->where('id_proyecto', $id)
            ->whereIn('tipo', [self::TIPO_DOCUMENTO, 'pdf', 'documentacion', 'presentacion'])
            ->whereNull('deleted_at')
            ->max('orden');

        foreach ($files as $index => $file) {
            $upload = $this->uploadProjectFileToSupabase($file, "projects/{$id}/documents");
            $path = $upload['path'];
            $url = $upload['url'];
            $mime = $file->getMimeType();
            $isPdf = Str::contains((string) $mime, 'pdf');

            DB::table('proyecto_evidencias')->insert([
                'id_proyecto' => $id,
                'titulo' => $file->getClientOriginalName() ?: ('Documento ' . ($index + 1)),
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

        return response()->json(['documents' => $docs, 'documentos' => $docs, 'urls' => collect($docs)->pluck('url')->values()]);
    }

    public function deleteDocuments(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);
        if (!$this->findProjectForUser($userId, $id)) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        if (! $this->resolveProjectPermissions($userId, $id)['puede_editar']) {
            return response()->json(['message' => 'No tienes permiso para editar este proyecto'], 403);
        }

        $urls = collect($request->input('urls', $request->input('documentos', [])))
            ->filter(fn ($u) => is_string($u) && trim($u) !== '')
            ->values();

        if ($urls->isEmpty()) {
            return response()->json(['message' => 'Sin documentos para eliminar']);
        }

        $rows = DB::table('proyecto_evidencias')
            ->where('id_proyecto', $id)
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

        if (!empty($toDeleteIds)) {
            DB::table('proyecto_evidencias')->whereIn('id_evidencia', $toDeleteIds)->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Documentos eliminados correctamente']);
    }

    public function reorderDocuments(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'Reordenado no implementado', 'ok' => true]);
    }

    public function updateLinks(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);
        $project = $this->findProjectForUser($userId, $id);
        if (!$project) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        if (! $this->resolveProjectPermissions($userId, $id)['puede_editar']) {
            return response()->json(['message' => 'No tienes permiso para editar este proyecto'], 403);
        }

        $payload = $request->validate([
            'url_repositorios' => 'sometimes|array',
            'url_repositorios.*' => 'nullable|string|max:500',
            'url_demo' => 'nullable|string|max:500',
            'url_videos' => 'sometimes|array',
            'url_videos.*' => 'nullable|string|max:500',
        ]);

        $this->syncLinkEvidences($id, $payload);
        $this->syncProjectRepositories($userId, $id, $payload);
        $this->syncProjectTechnologies($userId, $id, $payload);

        $updated = $this->findProjectForUser($userId, $id);
        return response()->json(['data' => $this->serializeProject($updated)]);
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $rules = [
            'titulo' => [$partial ? 'sometimes' : 'required', 'string', 'max:200'],
            'descripcion' => 'nullable|string|max:600',
            'estado' => [
                $partial ? 'sometimes' : 'required',
                'string',
                'max:30',
                'not_in:sin_especificar',
                'in:borrador,publicado,archivado,en_desarrollo,desarrollo,pausado,terminado,mantenimiento,versionado,cancelado',
            ],
            'estado_publicacion' => 'nullable|string|max:30|in:borrador,publicado,archivado',
            'estado_desarrollo' => 'nullable|string|max:30|in:sin_especificar,en_desarrollo,desarrollo,pausado,terminado,mantenimiento,versionado,cancelado',
            'tipo' => 'nullable|string|max:80',
            'desarrollado_para' => 'nullable|string|max:80',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'es_publico' => 'nullable|boolean',
            'rol' => 'nullable|string|max:100',
            'descripcion_aporte' => 'nullable|string|max:600',
            'url_repositorios' => 'nullable|array',
            'url_repositorios.*' => 'nullable|string|max:500',
            'url_demo' => 'nullable|string|max:500',
            'url_videos' => 'nullable|array',
            'url_videos.*' => 'nullable|string|max:500',
            'etiquetas' => 'nullable|array',
            'etiquetas.*' => 'nullable|string|max:100',
            'tecnologias' => 'nullable|array',
            'tecnologias.*' => 'nullable|string|max:100',
        ];

        return $request->validate($rules);
    }

    private function findProjectForUser(int $userId, int $id): ?array
    {
        if ($userId <= 0 || $id <= 0) {
            return null;
        }

        $row = DB::table('participaciones as p')
            ->join('proyectos as pr', 'pr.id_proyecto', '=', 'p.id_proyecto')
            ->where('p.id_usuario', $userId)
            ->where('pr.id_proyecto', $id)
            ->whereNull('p.deleted_at')
            ->whereNull('pr.deleted_at')
            ->select(
                'pr.*',
                'p.id_participacion',
                'p.id_usuario as participacion_id_usuario',
                'p.rol',
                'p.descripcion_aporte',
                'p.es_propietario',
                'p.participacion_validada',
                'p.visibilidad',
                'p.fecha_inicio as part_fecha_inicio',
                'p.fecha_fin as part_fecha_fin'
            )
            ->first();

        return $row ? (array) $row : null;
    }

    private function serializeProject(array $project, ?array $indexContext = null): array
    {
        $id = (int) $project['id_proyecto'];

        $evidencias = $indexContext !== null
            ? collect($indexContext['evidencias'][$id] ?? [])
            : DB::table('proyecto_evidencias')
                ->where('id_proyecto', $id)
                ->whereNull('deleted_at')
                ->orderBy('orden')
                ->orderBy('id_evidencia')
                ->get()
                ->map(fn ($ev) => $this->serializeProjectEvidence($ev))
                ->values();

        $repositoriosDetalle = $indexContext !== null
            ? collect($indexContext['repositorios'][$id] ?? [])
            : DB::table('proyecto_repositorios as pr')
                ->leftJoin('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
                ->where('pr.id_proyecto', $id)
                ->whereNull('pr.deleted_at')
                ->orderBy('pr.id_proyecto_repositorio')
                ->select(
                    'pr.id_proyecto_repositorio',
                    'pr.nombre',
                    'pr.tipo',
                    'pr.proveedor',
                    'pr.url_repositorio',
                    'pr.descripcion',
                    'rg.github_owner',
                    'rg.github_repo_name',
                    'rg.github_description',
                    'rg.github_homepage',
                    'rg.stars_count',
                    'rg.forks_count',
                    'rg.commits_count',
                    'rg.contributors_count',
                    'rg.last_push_at',
                    'rg.last_sync_at'
                )
                ->get()
                ->map(fn ($repo) => (array) $repo)
                ->values();

        $repositorios = $repositoriosDetalle
            ->pluck('url_repositorio')
            ->filter()
            ->values();

        $tecnologias = $indexContext !== null
            ? collect($indexContext['tecnologias'][$id] ?? [])
            : DB::table('uso_tecnologias as ut')
                ->join('tecnologias as t', 't.id_tecnologia', '=', 'ut.id_tecnologia')
                ->where('ut.id_proyecto', $id)
                ->whereNull('ut.deleted_at')
                ->whereNull('t.deleted_at')
                ->orderByDesc('ut.es_principal')
                ->orderBy('t.nombre')
                ->select('t.id_tecnologia', 't.nombre', 't.tipo', 't.icono_url', 't.color')
                ->get()
                ->values();

        $tecnologiaNombres = $tecnologias
            ->pluck('nombre')
            ->filter()
            ->values();

        $participantesCount = $indexContext !== null
            ? (int) ($indexContext['participantes_count'][$id] ?? 0)
            : DB::table('participaciones')
                ->where('id_proyecto', $id)
                ->whereNull('deleted_at')
                ->count();

        $currentUserId = (int) ($project['participacion_id_usuario'] ?? 0);
        $permissions = $indexContext !== null
            ? ($indexContext['permisos'][$id] ?? $this->defaultProjectPermissions())
            : ($currentUserId > 0
                ? $this->resolveProjectPermissions($currentUserId, $id)
                : $this->defaultProjectPermissions());
        $configuration = $indexContext !== null
            ? ($indexContext['configuraciones'][$id] ?? $this->defaultProjectConfiguration())
            : $this->getProjectConfiguration($id);

        return [
            ...$project,
            'id' => $id,
            'id_proyecto' => $id,
            'configuracion' => $configuration,
            'permisos' => $permissions,
            'puede_editar' => $permissions['puede_editar'],
            'puede_eliminar' => $permissions['puede_eliminar'],
            'puede_configurar' => $permissions['puede_configurar'],
            'puede_remover_participantes_sin_validacion' => $permissions['puede_remover_participantes_sin_validacion'],
            'estado' => $this->mapEstadoToFrontend($project['estado_publicacion'] ?? null, $project['estado_desarrollo'] ?? null),
            'tipo' => $project['categoria_proyecto'] ?? 'sin_especificar',
            'desarrollado_para' => $project['plataforma_objetivo'] ?? 'sin_especificar',
            'url_repositorios' => $repositorios->all(),
            'url_repositorio' => $repositorios->first() ?? '',
            'repositorios_detalle' => $repositoriosDetalle->all(),
            'etiquetas' => $tecnologiaNombres->all(),
            'tecnologias' => $tecnologiaNombres->all(),
            'tecnologias_detalle' => $tecnologias->map(fn ($tech) => (array) $tech)->all(),
            'participantes_count' => $participantesCount,
            'puede_desvincular_participacion' => $permissions['puede_desvincular_participacion'],
            'participacion' => [
                'id_participacion' => $project['id_participacion'] ?? null,
                'es_propietario' => (bool) ($project['es_propietario'] ?? false),
                'participacion_validada' => (bool) ($project['participacion_validada'] ?? false),
                'rol' => $project['rol'] ?? null,
                'descripcion_aporte' => $project['descripcion_aporte'] ?? null,
                'visibilidad' => $project['visibilidad'] ?? 'publico',
                'fecha_inicio' => $project['part_fecha_inicio'] ?? null,
                'fecha_fin' => $project['part_fecha_fin'] ?? null,
            ],
            'evidencias' => $evidencias,
        ];
    }

    private function serializeProjectEvidence(object|array $evidence): array
    {
        $arr = (array) $evidence;
        unset($arr['group_id_proyecto']);
        $arr['archivo_url'] = $arr['url'] ?? null;

        if (in_array(strtolower((string) ($arr['tipo'] ?? '')), [self::TIPO_IMAGEN, 'captura'], true)) {
            $variants = $this->profileImageVariants->getProjectVariantUrls($arr['url'] ?? null);
            $arr['imagen_card_url'] = $variants['card'] ?? null;
            $arr['imagen_detail_url'] = $variants['detail'] ?? null;
        }

        return $arr;
    }

    private function loadProjectIndexContext($projects, int $userId): array
    {
        $ids = $projects
            ->pluck('id_proyecto')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if ($ids === []) {
            return [
                'evidencias' => [],
                'repositorios' => [],
                'tecnologias' => [],
                'participantes_count' => [],
                'configuraciones' => [],
                'permisos' => [],
            ];
        }

        $evidencias = DB::table('proyecto_evidencias')
            ->whereIn('id_proyecto', $ids)
            ->whereNull('deleted_at')
            ->orderBy('id_proyecto')
            ->orderBy('orden')
            ->orderBy('id_evidencia')
            ->get()
            ->groupBy('id_proyecto')
            ->map(fn ($items) => $items->map(fn ($item) => $this->serializeProjectEvidence($item))->values()->all())
            ->all();

        $repositorios = DB::table('proyecto_repositorios as pr')
            ->leftJoin('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->whereIn('pr.id_proyecto', $ids)
            ->whereNull('pr.deleted_at')
            ->orderBy('pr.id_proyecto')
            ->orderBy('pr.id_proyecto_repositorio')
            ->select(
                'pr.id_proyecto as group_id_proyecto',
                'pr.id_proyecto_repositorio',
                'pr.nombre',
                'pr.tipo',
                'pr.proveedor',
                'pr.url_repositorio',
                'pr.descripcion',
                'rg.github_owner',
                'rg.github_repo_name',
                'rg.github_description',
                'rg.github_homepage',
                'rg.stars_count',
                'rg.forks_count',
                'rg.commits_count',
                'rg.contributors_count',
                'rg.last_push_at',
                'rg.last_sync_at'
            )
            ->get()
            ->groupBy('group_id_proyecto')
            ->map(fn ($items) => $items->map(function ($item) {
                $arr = (array) $item;
                unset($arr['group_id_proyecto']);
                return $arr;
            })->values()->all())
            ->all();

        $tecnologias = DB::table('uso_tecnologias as ut')
            ->join('tecnologias as t', 't.id_tecnologia', '=', 'ut.id_tecnologia')
            ->whereIn('ut.id_proyecto', $ids)
            ->whereNull('ut.deleted_at')
            ->whereNull('t.deleted_at')
            ->orderBy('ut.id_proyecto')
            ->orderByDesc('ut.es_principal')
            ->orderBy('t.nombre')
            ->select('ut.id_proyecto as group_id_proyecto', 't.id_tecnologia', 't.nombre', 't.tipo', 't.icono_url', 't.color')
            ->get()
            ->groupBy('group_id_proyecto')
            ->map(fn ($items) => $items->map(function ($item) {
                $arr = (array) $item;
                unset($arr['group_id_proyecto']);
                return $arr;
            })->values()->all())
            ->all();

        $counts = DB::table('participaciones')
            ->whereIn('id_proyecto', $ids)
            ->whereNull('deleted_at')
            ->select(
                'id_proyecto',
                DB::raw('COUNT(*) as participantes_count'),
                DB::raw('SUM(CASE WHEN es_propietario IS TRUE THEN 1 ELSE 0 END) as propietarios_count')
            )
            ->groupBy('id_proyecto')
            ->get()
            ->keyBy('id_proyecto');

        $configuraciones = DB::table('proyecto_configuraciones')
            ->whereIn('id_proyecto', $ids)
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->id_proyecto => $this->normalizeProjectConfiguration((array) $row, (int) $row->id_proyecto),
            ])
            ->all();

        $validaciones = DB::table('proyecto_repositorios as pr')
            ->join('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->join('usuario_repositorio_validaciones as urv', function ($join) use ($userId) {
                $join->on('urv.id_repositorio_github', '=', 'rg.id_repositorio_github')
                    ->where('urv.id_usuario', '=', $userId);
            })
            ->whereIn('pr.id_proyecto', $ids)
            ->where('pr.proveedor', 'github')
            ->whereNull('pr.deleted_at')
            ->whereRaw('urv.validado = TRUE')
            ->select('pr.id_proyecto', 'urv.relacion_github', 'urv.es_propietario', 'urv.permisos_github')
            ->get()
            ->groupBy('id_proyecto');

        $permisos = [];
        $participantesCount = [];
        foreach ($projects as $project) {
            $projectArray = (array) $project;
            $id = (int) $projectArray['id_proyecto'];
            $countsForProject = $counts->get($id);
            $participantesCount[$id] = (int) ($countsForProject->participantes_count ?? 0);
            $config = $configuraciones[$id] ?? $this->defaultProjectConfiguration();
            $config['id_proyecto'] = $id;
            $configuraciones[$id] = $config;
            $permisos[$id] = $this->resolveProjectPermissionsFromLoadedData(
                $projectArray,
                $config,
                $validaciones->get($id, collect()),
                (int) ($countsForProject->participantes_count ?? 0),
                (int) ($countsForProject->propietarios_count ?? 0)
            );
        }

        return [
            'evidencias' => $evidencias,
            'repositorios' => $repositorios,
            'tecnologias' => $tecnologias,
            'participantes_count' => $participantesCount,
            'configuraciones' => $configuraciones,
            'permisos' => $permisos,
        ];
    }

    private function defaultProjectConfiguration(): array
    {
        return [
            'permitir_participantes_sin_validacion' => false,
            'puede_editar_proyecto' => 'participantes_validados',
            'puede_administrar_proyecto' => 'propietarios',
            'github_nivel_autoridad' => 'maintainer',
            'github_prevalece_sobre_creador' => true,
            'visibilidad_usuario_sin_validacion' => 'visible',
            'permitir_remover_participantes_sin_validacion' => false,
        ];
    }

    private function getProjectConfiguration(int $idProyecto): array
    {
        $row = DB::table('proyecto_configuraciones')
            ->where('id_proyecto', $idProyecto)
            ->first();

        if (! $row) {
            $now = now();
            DB::table('proyecto_configuraciones')->insertOrIgnore([
                'id_proyecto' => $idProyecto,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = DB::table('proyecto_configuraciones')
                ->where('id_proyecto', $idProyecto)
                ->first();
        }

        return $this->normalizeProjectConfiguration((array) $row, $idProyecto);
    }

    private function normalizeProjectConfiguration(array $row, int $idProyecto): array
    {
        $config = [
            ...$this->defaultProjectConfiguration(),
            ...$row,
        ];

        foreach ([
            'permitir_participantes_sin_validacion',
            'github_prevalece_sobre_creador',
            'permitir_remover_participantes_sin_validacion',
        ] as $key) {
            $config[$key] = $this->truthy($config[$key] ?? false);
        }

        $config['id_proyecto'] = (int) ($config['id_proyecto'] ?? $idProyecto);

        return $config;
    }

    private function defaultProjectPermissions(): array
    {
        return [
            'puede_editar' => false,
            'puede_eliminar' => false,
            'puede_configurar' => false,
            'puede_administrar' => false,
            'puede_desvincular_participacion' => false,
            'puede_remover_participantes_sin_validacion' => false,
            'es_propietario' => false,
            'es_autoridad_github' => false,
            'participacion_validada' => false,
            'nivel_github' => null,
            'relacion_github' => null,
        ];
    }

    private function resolveProjectPermissions(int $userId, int $idProyecto): array
    {
        $participacion = DB::table('participaciones')
            ->where('id_usuario', $userId)
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->first();

        if (! $participacion) {
            return $this->defaultProjectPermissions();
        }

        $config = $this->getProjectConfiguration($idProyecto);
        $authority = $this->getGithubAuthorityForProject($userId, $idProyecto, $config);

        $isOwner = $this->truthy($participacion->es_propietario ?? false);
        $isGithubAuthority = (bool) ($authority['tiene_autoridad'] ?? false);
        $isValidated = (bool) ($participacion->participacion_validada ?? false)
            || $this->hasValidatedGithubParticipation($userId, $idProyecto);
        $githubOverridesCreator = (bool) ($config['github_prevalece_sobre_creador'] ?? true);
        $adminPolicy = $config['puede_administrar_proyecto'] ?? 'propietarios';
        $githubCanManage = $isGithubAuthority
            && ($githubOverridesCreator || $adminPolicy === 'autoridad_github');
        $canAdmin = $isOwner || $githubCanManage;

        $canEdit = match ($config['puede_editar_proyecto'] ?? 'participantes_validados') {
            'propietarios' => $isOwner || ($githubOverridesCreator && $isGithubAuthority),
            'autoridad_github' => $isOwner || $isGithubAuthority,
            'participantes' => true,
            default => $isOwner || ($githubOverridesCreator && $isGithubAuthority) || $isValidated,
        };

        $canRemoveUnvalidated = (bool) ($config['permitir_remover_participantes_sin_validacion'] ?? false)
            && $canAdmin;

        $activeParticipants = $this->activeProjectParticipantsCount($idProyecto);
        $isSoleOwner = $this->isSoleActiveProjectOwner($participacion, $idProyecto);

        return [
            'puede_editar' => $canEdit,
            'puede_eliminar' => $isOwner,
            'puede_configurar' => $canAdmin,
            'puede_administrar' => $canAdmin,
            'puede_desvincular_participacion' => $activeParticipants > 1 && ! $isSoleOwner,
            'puede_remover_participantes_sin_validacion' => $canRemoveUnvalidated,
            'es_propietario' => $isOwner,
            'es_autoridad_github' => $isGithubAuthority,
            'participacion_validada' => $isValidated,
            'nivel_github' => $authority['nivel'] ?? null,
            'relacion_github' => $authority['relacion'] ?? null,
        ];
    }

    private function resolveProjectPermissionsFromLoadedData(
        array $participacion,
        array $config,
        $validaciones,
        int $activeParticipants,
        int $activeOwners
    ): array {
        $authority = $this->getGithubAuthorityFromValidations($validaciones, $config);
        $isOwner = $this->truthy($participacion['es_propietario'] ?? false);
        $isGithubAuthority = (bool) ($authority['tiene_autoridad'] ?? false);
        $isValidated = $this->truthy($participacion['participacion_validada'] ?? false)
            || $validaciones->isNotEmpty();
        $githubOverridesCreator = (bool) ($config['github_prevalece_sobre_creador'] ?? true);
        $adminPolicy = $config['puede_administrar_proyecto'] ?? 'propietarios';
        $githubCanManage = $isGithubAuthority
            && ($githubOverridesCreator || $adminPolicy === 'autoridad_github');
        $canAdmin = $isOwner || $githubCanManage;

        $canEdit = match ($config['puede_editar_proyecto'] ?? 'participantes_validados') {
            'propietarios' => $isOwner || ($githubOverridesCreator && $isGithubAuthority),
            'autoridad_github' => $isOwner || $isGithubAuthority,
            'participantes' => true,
            default => $isOwner || ($githubOverridesCreator && $isGithubAuthority) || $isValidated,
        };

        return [
            'puede_editar' => $canEdit,
            'puede_eliminar' => $isOwner,
            'puede_configurar' => $canAdmin,
            'puede_administrar' => $canAdmin,
            'puede_desvincular_participacion' => $activeParticipants > 1 && ! ($isOwner && $activeOwners <= 1),
            'puede_remover_participantes_sin_validacion' =>
                (bool) ($config['permitir_remover_participantes_sin_validacion'] ?? false) && $canAdmin,
            'es_propietario' => $isOwner,
            'es_autoridad_github' => $isGithubAuthority,
            'participacion_validada' => $isValidated,
            'nivel_github' => $authority['nivel'] ?? null,
            'relacion_github' => $authority['relacion'] ?? null,
        ];
    }

    private function activeProjectParticipantsCount(int $idProyecto): int
    {
        return DB::table('participaciones')
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->count();
    }

    private function activeProjectOwnersCount(int $idProyecto): int
    {
        return DB::table('participaciones')
            ->where('id_proyecto', $idProyecto)
            ->whereRaw('es_propietario = TRUE')
            ->whereNull('deleted_at')
            ->count();
    }

    private function isSoleActiveProjectOwner(object $participacion, int $idProyecto): bool
    {
        if (! $this->truthy($participacion->es_propietario ?? false)) {
            return false;
        }

        return $this->activeProjectOwnersCount($idProyecto) <= 1;
    }

    private function hasValidatedGithubParticipation(int $userId, int $idProyecto): bool
    {
        $repoIds = $this->projectGithubRepositoryIds($idProyecto);

        if (empty($repoIds)) {
            return false;
        }

        return DB::table('usuario_repositorio_validaciones')
            ->where('id_usuario', $userId)
            ->whereIn('id_repositorio_github', $repoIds)
            ->whereRaw('validado = TRUE')
            ->exists();
    }

    private function getGithubAuthorityForProject(int $userId, int $idProyecto, array $config): array
    {
        $repoIds = $this->projectGithubRepositoryIds($idProyecto);

        if (empty($repoIds)) {
            return ['tiene_autoridad' => false, 'nivel' => null, 'relacion' => null];
        }

        $validaciones = DB::table('usuario_repositorio_validaciones')
            ->where('id_usuario', $userId)
            ->whereIn('id_repositorio_github', $repoIds)
            ->whereRaw('validado = TRUE')
            ->get();

        return $this->getGithubAuthorityFromValidations($validaciones, $config);
    }

    private function getGithubAuthorityFromValidations($validaciones, array $config): array
    {
        foreach ($validaciones as $validacion) {
            $relation = Str::lower((string) ($validacion->relacion_github ?? ''));
            $permissions = $this->decodeGithubPermissions($validacion->permisos_github ?? null);
            $isOwner = (bool) ($validacion->es_propietario ?? false) || $relation === 'owner';
            $hasMaintainer = $isOwner || in_array($relation, ['maintainer', 'admin'], true) || (bool) ($permissions['admin'] ?? false);
            $hasAdminPush = $hasMaintainer || (bool) ($permissions['push'] ?? false);

            $required = $config['github_nivel_autoridad'] ?? 'maintainer';
            $allowed = match ($required) {
                'owner' => $isOwner,
                'admin_push' => $hasAdminPush,
                default => $hasMaintainer,
            };

            if ($allowed) {
                return [
                    'tiene_autoridad' => true,
                    'nivel' => $isOwner ? 'owner' : ($hasMaintainer ? 'maintainer' : 'admin_push'),
                    'relacion' => $relation ?: null,
                ];
            }
        }

        return ['tiene_autoridad' => false, 'nivel' => null, 'relacion' => null];
    }

    private function projectGithubRepositoryIds(int $idProyecto): array
    {
        return DB::table('proyecto_repositorios as pr')
            ->join('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->where('pr.id_proyecto', $idProyecto)
            ->where('pr.proveedor', 'github')
            ->whereNull('pr.deleted_at')
            ->pluck('rg.id_repositorio_github')
            ->filter()
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();
    }

    private function decodeGithubPermissions(mixed $permissions): array
    {
        if (is_array($permissions)) {
            return $permissions;
        }

        if (is_string($permissions) && trim($permissions) !== '') {
            $decoded = json_decode($permissions, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function postgresBool(mixed $value): string
    {
        return $this->truthy($value) ? 'TRUE' : 'FALSE';
    }

    private function collectProjectParticipants(int $idProyecto, int $requestUserId): array
    {
        $config = $this->getProjectConfiguration($idProyecto);
        $permissions = $this->resolveProjectPermissions($requestUserId, $idProyecto);
        $hideUnvalidated = ($config['visibilidad_usuario_sin_validacion'] ?? 'visible') === 'oculto';
        $canManageUnvalidated = (bool) ($permissions['puede_remover_participantes_sin_validacion'] ?? false);

        $repos = DB::table('proyecto_repositorios as pr')
            ->leftJoin('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->where('pr.id_proyecto', $idProyecto)
            ->where('pr.proveedor', 'github')
            ->whereNull('pr.deleted_at')
            ->select(
                'pr.id_proyecto_repositorio',
                'pr.url_repositorio',
                'rg.id_repositorio_github',
                'rg.github_owner',
                'rg.github_repo_name'
            )
            ->get();

        $repoGithubIds = $repos
            ->pluck('id_repositorio_github')
            ->filter()
            ->values();

        $systemRows = DB::table('participaciones as p')
            ->join('usuarios as u', 'u.id_usuario', '=', 'p.id_usuario')
            ->leftJoin('perfiles as pe', 'pe.usuario_id', '=', 'u.id_usuario')
            ->leftJoin('cuentas_oauth as co', function ($join) {
                $join->on('co.usuario_id', '=', 'u.id_usuario')
                    ->where('co.provider', '=', 'github');
            })
            ->where('p.id_proyecto', $idProyecto)
            ->whereNull('p.deleted_at')
            ->select(
                'p.id_participacion',
                'p.id_usuario',
                'p.rol',
                'p.descripcion_aporte',
                'p.es_propietario',
                'p.participacion_validada',
                'u.nombre',
                'u.apellido',
                'u.correo',
                'pe.foto_perfil',
                'co.id_cuenta_oauth',
                'co.provider_user_id',
                'co.nombre as github_nombre',
                'co.foto_url as github_foto_url'
            )
            ->get();

        $userIds = $systemRows->pluck('id_usuario')->filter()->values();
        $validaciones = $repoGithubIds->isEmpty() || $userIds->isEmpty()
            ? collect()
            : DB::table('usuario_repositorio_validaciones')
                ->whereIn('id_usuario', $userIds->all())
                ->whereIn('id_repositorio_github', $repoGithubIds->all())
                ->get()
                ->groupBy('id_usuario');

        $participants = [];
        foreach ($systemRows as $row) {
            $userValidaciones = $validaciones->get($row->id_usuario, collect());
            $validacion = $userValidaciones->first(fn ($item) => (bool) $item->validado)
                ?? $userValidaciones->first();
            $validado = (bool) ($validacion?->validado ?? $row->participacion_validada ?? false);
            $tipo = $validado
                ? 'usuario_github_validado'
                : 'usuario_sin_validacion_github';

            if (! $validado && $hideUnvalidated && ! $canManageUnvalidated) {
                continue;
            }

            $item = [
                'id' => 'participacion-' . $row->id_participacion,
                'id_participacion' => (int) $row->id_participacion,
                'id_usuario' => (int) $row->id_usuario,
                'nombre' => trim(($row->nombre ?? '') . ' ' . ($row->apellido ?? '')),
                'email' => $row->correo,
                'rol' => $row->rol,
                'descripcion_aporte' => $row->descripcion_aporte,
                'es_propietario' => (bool) $row->es_propietario,
                'foto_perfil' => $row->foto_perfil,
                'avatar_thumb_url' => $this->profileImageVariants->getVariantUrl($row->foto_perfil, 'thumb'),
                'github_avatar_url' => $row->github_foto_url,
                'avatar_url' => $row->foto_perfil ?: $row->github_foto_url,
                'source' => 'sistema',
                'tipo_participante' => $tipo,
                'origen_participante' => $tipo,
                'tiene_cuenta' => true,
                'tiene_vinculacion_github' => ! is_null($row->id_cuenta_oauth),
                'validacion_github' => $validado,
                'github_id' => $row->provider_user_id,
                'github_username' => $row->github_nombre,
                'validacion' => [
                    'validado' => $validado,
                    'relacion_github' => $validacion->relacion_github ?? 'unknown',
                    'es_propietario' => (bool) ($validacion->es_propietario ?? false),
                    'ultima_verificacion_at' => $validacion->ultima_verificacion_at ?? null,
                ],
            ];

            $participants[] = $item;
        }

        return collect($participants)
            ->unique(function ($item) {
                if (! empty($item['id_usuario'])) {
                    return 'usuario:' . $item['id_usuario'];
                }

                if (! empty($item['github_id'])) {
                    return 'github-id:' . $item['github_id'];
                }

                return 'github-login:' . strtolower((string) ($item['github_username'] ?? $item['id']));
            })
            ->sortBy([
                fn ($a, $b) => $this->participantTypeOrder($a) <=> $this->participantTypeOrder($b),
                fn ($a, $b) => ((bool) ($b['es_propietario'] ?? false)) <=> ((bool) ($a['es_propietario'] ?? false)),
                fn ($a, $b) => strcmp((string) ($a['nombre'] ?? ''), (string) ($b['nombre'] ?? '')),
            ])
            ->values()
            ->all();
    }

    private function participantTypeOrder(array $participant): int
    {
        return match ($participant['tipo_participante'] ?? '') {
            'usuario_github_validado' => 0,
            'usuario_sin_validacion_github' => 1,
            default => 2,
        };
    }

    private function syncLinkEvidences(int $idProyecto, array $payload): void
    {
        $hasDemo = array_key_exists('url_demo', $payload);
        $hasVideos = array_key_exists('url_videos', $payload);

        if (!$hasDemo && !$hasVideos) {
            return;
        }

        DB::table('proyecto_evidencias')
            ->where('id_proyecto', $idProyecto)
            ->whereIn('tipo', [self::TIPO_DEMO, self::TIPO_VIDEO])
            ->whereNull('deleted_at')
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $insert = [];
        $now = now();

        $demo = isset($payload['url_demo']) && is_string($payload['url_demo']) ? trim($payload['url_demo']) : '';
        if ($demo !== '') {
            $insert[] = [
                'id_proyecto' => $idProyecto,
                'titulo' => 'Demo',
                'descripcion' => null,
                'tipo' => self::TIPO_DEMO,
                'url' => $demo,
                'archivo_path' => null,
                'mime_type' => null,
                'tamanio_bytes' => null,
                'es_portada' => 'false',
                'es_visible' => 'true',
                'orden' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $videos = collect($payload['url_videos'] ?? [])
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->values();

        foreach ($videos as $idx => $url) {
            $insert[] = [
                'id_proyecto' => $idProyecto,
                'titulo' => 'Video',
                'descripcion' => null,
                'tipo' => self::TIPO_VIDEO,
                'url' => trim($url),
                'archivo_path' => null,
                'mime_type' => null,
                'tamanio_bytes' => null,
                'es_portada' => 'false',
                'es_visible' => 'true',
                'orden' => $idx,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($insert)) {
            DB::table('proyecto_evidencias')->insert($insert);
        }
    }

    private function syncProjectRepositories(int $userId, int $idProyecto, array $payload): void
    {
        if (! array_key_exists('url_repositorios', $payload)) {
            return;
        }

        DB::table('proyecto_evidencias')
            ->where('id_proyecto', $idProyecto)
            ->where('tipo', self::TIPO_REPOS)
            ->whereNull('deleted_at')
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $githubUrls = collect($payload['url_repositorios'] ?? [])
            ->filter(fn ($url) => is_string($url) && str_contains(strtolower($url), 'github.com/'))
            ->values()
            ->all();

        if (empty($githubUrls)) {
            return;
        }

        $result = $this->githubRepositorySyncService->syncProjectRepoUrlsForUsuario(
            $userId,
            $idProyecto,
            $githubUrls,
        );

        if (($result['status'] ?? 'error') !== 'success') {
            abort($result['http_status'] ?? 422, $result['message'] ?? 'No se pudieron validar los repositorios.');
        }
    }

    private function syncProjectTechnologies(int $userId, int $idProyecto, array $payload): void
    {
        $hasEtiquetas = array_key_exists('etiquetas', $payload);
        $hasTecnologias = array_key_exists('tecnologias', $payload);
        $hasRepos = array_key_exists('url_repositorios', $payload);

        if (! $hasEtiquetas && ! $hasTecnologias && ! $hasRepos) {
            return;
        }

        $manuales = collect($payload['tecnologias'] ?? $payload['etiquetas'] ?? []);
        $detectadas = $manuales->isEmpty()
            ? $this->detectTechnologiesFromRepositories($userId, $payload['url_repositorios'] ?? [])
            : [];

        $nombres = $manuales
            ->merge($detectadas)
            ->map(fn ($value) => is_string($value) ? trim($value) : '')
            ->filter()
            ->unique(fn ($value) => Str::lower($value))
            ->values();

        if ($nombres->isEmpty()) {
            DB::table('uso_tecnologias')
                ->where('id_proyecto', $idProyecto)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
            return;
        }

        $ids = [];

        foreach ($nombres as $index => $nombre) {
            $resultado = $this->tecnologiaService->agregarBasicaPorNombre($nombre);
            $tecnologia = $resultado['tecnologia'] ?? null;

            if (! $tecnologia?->id_tecnologia) {
                continue;
            }

            $ids[] = (int) $tecnologia->id_tecnologia;

            DB::table('uso_tecnologias')->updateOrInsert(
                [
                    'id_proyecto' => $idProyecto,
                    'id_tecnologia' => (int) $tecnologia->id_tecnologia,
                ],
                [
                    'version_usada' => null,
                    'porcentaje_uso' => null,
                    'es_principal' => $index < 3 ? 'true' : 'false',
                    'es_visible' => 'true',
                    'deleted_at' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        DB::table('uso_tecnologias')
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->when(! empty($ids), fn ($query) => $query->whereNotIn('id_tecnologia', $ids))
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function detectTechnologiesFromRepositories(int $userId, array $repoUrls): array
    {
        $technologies = [];

        foreach ($repoUrls as $repoUrl) {
            if (! is_string($repoUrl) || trim($repoUrl) === '') {
                continue;
            }

            $result = $this->githubRepositorySyncService->fetchRepoLanguagesForUsuario($userId, $repoUrl);

            if (($result['status'] ?? null) !== 'success' || ! is_array($result['languages'] ?? null)) {
                continue;
            }

            $technologies = array_merge($technologies, $result['languages']);
        }

        return $technologies;
    }

    private function mapPlataforma(?string $value): string
    {
        $map = [
            'web' => 'web',
            'movil' => 'movil',
            'mobile' => 'movil',
            'tablet' => 'movil',
            'escritorio' => 'escritorio',
            'desktop' => 'escritorio',
            'web_movil' => 'web_movil',
            'tablet_web' => 'web_movil',
            'multiplataforma' => 'multiplataforma',
            'api' => 'api_backend',
            'api_backend' => 'api_backend',
            'servidor' => 'api_backend',
            'datos_ml' => 'datos_ml',
            'data' => 'datos_ml',
            'data_bi' => 'datos_ml',
            'ia_ml' => 'datos_ml',
            'machine_learning' => 'datos_ml',
            'terminal' => 'cli',
            'cli' => 'cli',
            'iot' => 'iot',
            'auto' => 'iot',
            'reloj' => 'iot',
            'televisor' => 'multiplataforma',
            'consola' => 'multiplataforma',
            'kiosko' => 'multiplataforma',
            'otro' => 'otro',
        ];

        $key = Str::lower(trim((string) $value));
        return $map[$key] ?? 'sin_especificar';
    }

    private function mapCategoria(?string $value): string
    {
        $map = [
            'sin_especificar' => 'sin_especificar',
            'web' => 'portafolio',
            'app_web' => 'productividad',
            'movil' => 'productividad',
            'desktop' => 'productividad',
            'videojuego' => 'videojuego',
            'api' => 'herramienta_desarrollo',
            'microservicio' => 'herramienta_desarrollo',
            'ecommerce' => 'ecommerce',
            'dashboard' => 'dashboard_bi',
            'sistema_gestion' => 'gestion_empresarial',
            'saas' => 'productividad',
            'ia_ml' => 'herramienta_desarrollo',
            'data_bi' => 'dashboard_bi',
            'iot' => 'herramienta_desarrollo',
            'automatizacion' => 'herramienta_desarrollo',
            'plugin' => 'herramienta_desarrollo',
            'libreria' => 'herramienta_desarrollo',
            'bot' => 'herramienta_desarrollo',
            'blockchain' => 'seguridad',
            'ar_vr' => 'entretenimiento',
            'educativo' => 'educativo',
            'investigacion' => 'educativo',
            'otro' => 'otro',
            'portafolio' => 'portafolio',
            'financiero' => 'financiero',
            'marketplace' => 'marketplace',
            'salud' => 'salud',
            'administrativo' => 'administrativo',
            'red_social' => 'red_social',
            'dashboard_bi' => 'dashboard_bi',
            'gestion_empresarial' => 'gestion_empresarial',
            'productividad' => 'productividad',
            'seguridad' => 'seguridad',
            'entretenimiento' => 'entretenimiento',
            'herramienta_desarrollo' => 'herramienta_desarrollo',
        ];

        $key = Str::lower(trim((string) $value));
        return $map[$key] ?? 'sin_especificar';
    }

    private function mapEstadoPublicacion(?string $value): string
    {
        $key = Str::lower(trim((string) $value));
        return match ($key) {
            'publicado' => 'publicado',
            'archivado' => 'archivado',
            default => 'borrador',
        };
    }

    private function mapEstadoDesarrollo(?string $value): string
    {
        $key = Str::lower(trim((string) $value));
        return match ($key) {
            'desarrollo', 'en_desarrollo' => 'en_desarrollo',
            'terminado' => 'terminado',
            'mantenimiento' => 'mantenimiento',
            'versionado' => 'versionado',
            'pausado' => 'pausado',
            'cancelado' => 'cancelado',
            default => 'sin_especificar',
        };
    }

    private function mapEstadoToFrontend(?string $estadoPublicacion, ?string $estadoDesarrollo): string
    {
        if ($estadoPublicacion === 'archivado') {
            return 'archivado';
        }

        if ($estadoPublicacion === 'publicado') {
            return 'publicado';
        }

        if ($estadoPublicacion === 'borrador' && (! $estadoDesarrollo || $estadoDesarrollo === 'sin_especificar')) {
            return 'borrador';
        }

        return in_array($estadoDesarrollo, [
            'en_desarrollo',
            'pausado',
            'terminado',
            'mantenimiento',
            'versionado',
            'cancelado',
        ], true) ? $estadoDesarrollo : 'borrador';
    }

    private function normalizeStoragePathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || trim($path) === '') {
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
        $path = trim($folder, '/') . '/' . Str::uuid() . '.' . $extension;
        $content = file_get_contents($file->getRealPath());

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($urlBase, '/') . '/storage/v1/object/' . $bucket . '/' . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'apikey: ' . $key,
                'Content-Type: ' . ($file->getMimeType() ?: 'application/octet-stream'),
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
            'url' => rtrim($urlBase, '/') . '/storage/v1/object/public/' . $bucket . '/' . $path,
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
            $prefix = rtrim($urlBase, '/') . '/storage/v1/object/public/' . $bucket . '/';
            $storagePath = str_starts_with($url, $prefix)
                ? substr($url, strlen($prefix))
                : '';
        }

        if ($storagePath === '') {
            return;
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($urlBase, '/') . '/storage/v1/object/' . $bucket . '/' . ltrim($storagePath, '/'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'apikey: ' . $key,
            ],
        ]);

        curl_exec($ch);
        curl_close($ch);
    }
}
