<?php

namespace App\Http\Controllers\Api\Auth;

use App\Services\api\Auth\OAuthProviderAuthorizationService;
use App\Services\api\AuthService;
use App\Services\api\GithubRepositorySyncService;
use App\Services\api\SeccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

use App\Services\api\ProyectoNotificacionGuardadoService;

class GithubAuthController extends ProviderOAuthController
{
    public function __construct(
        AuthService $authService,
        OAuthProviderAuthorizationService $authorizationService,
        SeccionService $seccionService,
        private readonly GithubRepositorySyncService $githubRepositorySyncService,
        private readonly ProyectoNotificacionGuardadoService $proyectoNotificacionGuardadoService,
    ) {
        parent::__construct($authService, $authorizationService, $seccionService);
    }

    protected function provider(): string
    {
        return 'github';
    }

    public function syncRepos(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No hay sesion activa'], 401);
        }

        $result = $this->githubRepositorySyncService->syncForUsuario((int) $user->id_usuario);

        return match ($result['status'] ?? 'error') {
            'success' => response()->json($result),
            'not_linked' => response()->json($result, 404),
            'missing_token' => response()->json($result, 422),
            'invalid_token' => response()->json($result, 401),
            default => response()->json($result, 502),
        };
    }

    public function detectedRepos(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No hay sesion activa'], 401);
        }

        $refresh = filter_var($request->query('refresh', false), FILTER_VALIDATE_BOOLEAN);

        if ($refreshResponse = $this->refreshGithubReposIfRequested((int) $user->id_usuario, $refresh)) {
            return $refreshResponse;
        }

        $repos = $this->getDetectedReposForUsuario((int) $user->id_usuario);

        return response()->json([
            'status' => 'success',
            'data' => $repos,
        ]);
    }

    public function detectedReposCount(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No hay sesion activa'], 401);
        }

        $refresh = filter_var($request->query('refresh', false), FILTER_VALIDATE_BOOLEAN);

        if ($refreshResponse = $this->refreshGithubReposIfRequested((int) $user->id_usuario, $refresh)) {
            return $refreshResponse;
        }

        return response()->json([
            'status' => 'success',
            'count' => $this->getDetectedReposForUsuario((int) $user->id_usuario, true),
        ]);
    }

    private function refreshGithubReposIfRequested(int $usuarioId, bool $refresh): ?JsonResponse
    {
        if (! $refresh) {
            return null;
        }

        $sync = $this->githubRepositorySyncService->syncForUsuario($usuarioId);

        if (($sync['status'] ?? 'error') === 'success') {
            return null;
        }

        return match ($sync['status'] ?? 'error') {
            'not_linked' => response()->json($sync, 404),
            'missing_token' => response()->json($sync, 422),
            'invalid_token' => response()->json($sync, 401),
            default => response()->json($sync, 502),
        };
    }

    private function getDetectedReposForUsuario(int $usuarioId, bool $countOnly = false): mixed
    {
        $query = DB::table('proyecto_repositorios as pr')
            ->join('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->leftJoin('usuario_repositorio_validaciones as urv', function ($join) use ($usuarioId) {
                $join->on('urv.id_repositorio_github', '=', 'rg.id_repositorio_github')
                    ->where('urv.id_usuario', '=', $usuarioId);
            })
            ->leftJoin('proyectos as p', function ($join) {
                $join->on('p.id_proyecto', '=', 'pr.id_proyecto')
                    ->whereNull('p.deleted_at');
            })
            ->leftJoin('participaciones as pa', function ($join) use ($usuarioId) {
                $join->on('pa.id_proyecto', '=', 'pr.id_proyecto')
                    ->where('pa.id_usuario', '=', $usuarioId)
                    ->whereNull('pa.deleted_at');
            })
            ->leftJoin('proyecto_configuraciones as pc', 'pc.id_proyecto', '=', 'pr.id_proyecto')
            ->where('pr.proveedor', 'github')
            ->whereNull('pr.deleted_at')
            ->where(function ($query) {
                $query->whereRaw('urv.validado = TRUE')
                    ->orWhereNotNull('pr.id_proyecto');
            })
            ->where(function ($query) {
                $query->whereNull('pr.id_proyecto')
                    ->orWhere(function ($projectQuery) {
                        $projectQuery->whereNotNull('p.id_proyecto')
                            ->whereNull('pa.id_participacion');
                    });
            })
            ->orderByDesc('pr.updated_at');

        if ($countOnly) {
            return $query
                ->select([
                    'pr.id_proyecto',
                    'urv.validado',
                    'pc.permitir_participantes_sin_validacion',
                ])
                ->get()
                ->filter(function ($row) {
                    $repoEnUso = ! is_null($row->id_proyecto);
                    $validado = $this->databaseBool($row->validado ?? false);
                    $permiteSinValidacion = $repoEnUso
                        && $this->databaseBool($row->permitir_participantes_sin_validacion ?? false);

                    return $validado || ($repoEnUso && $permiteSinValidacion);
                })
                ->count();
        }

        $rows = $query
            ->select([
                'pr.id_proyecto_repositorio',
                'pr.id_proyecto',
                'pr.nombre',
                'pr.url_repositorio',
                'pr.descripcion',
                'pr.tipo',
                'rg.id_repositorio_github',
                'rg.github_repo_id',
                'rg.github_owner',
                'rg.github_repo_name',
                'rg.is_private',
                'rg.is_archived',
                'rg.last_push_at',
                'rg.stars_count',
                'urv.validado',
                'urv.relacion_github',
                'urv.es_propietario',
                'urv.ultima_verificacion_at',
                'pc.permitir_participantes_sin_validacion',
                'p.titulo as proyecto_titulo',
                'p.descripcion as proyecto_descripcion',
                'p.estado_publicacion as proyecto_estado_publicacion',
                'p.estado_desarrollo as proyecto_estado_desarrollo',
            ])
            ->get();

        $repos = $rows
            ->map(function ($row) {
                $repoEnUso = ! is_null($row->id_proyecto);
                $validado = $this->databaseBool($row->validado ?? false);
                $permiteSinValidacion = $repoEnUso
                    && $this->databaseBool($row->permitir_participantes_sin_validacion ?? false);

                if (! $validado && ! ($repoEnUso && $permiteSinValidacion)) {
                    return null;
                }

                return [
                    'id_proyecto_repositorio' => $row->id_proyecto_repositorio,
                    'id_proyecto' => $row->id_proyecto,
                    'nombre' => $row->nombre,
                    'url_repositorio' => $row->url_repositorio,
                    'descripcion' => $row->descripcion,
                    'tipo' => $row->tipo,
                    'estado_vinculacion' => $repoEnUso ? 'en_uso' : 'libre',
                    'puede_unirse' => $repoEnUso && ($validado || $permiteSinValidacion),
                    'proyecto' => $repoEnUso ? [
                        'id_proyecto' => $row->id_proyecto,
                        'titulo' => $row->proyecto_titulo,
                        'descripcion' => $row->proyecto_descripcion,
                        'estado_publicacion' => $row->proyecto_estado_publicacion,
                        'estado_desarrollo' => $row->proyecto_estado_desarrollo,
                    ] : null,
                    'repo_github' => [
                        'id_repositorio_github' => $row->id_repositorio_github,
                        'github_repo_id' => $row->github_repo_id,
                        'owner' => $row->github_owner,
                        'repo_name' => $row->github_repo_name,
                        'is_private' => $this->databaseBool($row->is_private ?? false),
                        'is_archived' => $this->databaseBool($row->is_archived ?? false),
                        'last_push_at' => $row->last_push_at,
                        'stars_count' => $row->stars_count,
                    ],
                    'validacion' => [
                        'validado' => $validado,
                        'relacion_github' => $row->relacion_github ?? 'unknown',
                        'es_propietario' => $this->databaseBool($row->es_propietario ?? false),
                        'ultima_verificacion_at' => $row->ultima_verificacion_at ?? null,
                    ],
                ];
            })
            ->filter()
            ->values();

        return $repos;
    }

    private function databaseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }

    public function repoLanguages(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No hay sesion activa'], 401);
        }

        $validator = Validator::make($request->all(), [
            'repo_url' => ['required', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->githubRepositorySyncService->fetchRepoLanguagesForUsuario(
            (int) $user->id_usuario,
            (string) $validator->validated()['repo_url'],
        );

        return match ($result['status'] ?? 'error') {
            'success' => response()->json($result),
            'invalid_payload' => response()->json($result, 422),
            'not_linked' => response()->json($result, 404),
            'missing_token' => response()->json($result, 422),
            'invalid_token' => response()->json($result, 401),
            'repo_not_found_or_no_access' => response()->json($result, 404),
            'private_or_no_access' => response()->json([
                ...$result,
                'message' => 'Necesita vincular cuenta, actualmente no cuenta con los permisos.',
            ], 403),
            default => response()->json($result, 502),
        };
    }

    public function attachDetectedReposToProject(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No hay sesion activa'], 401);
        }

        $validator = Validator::make($request->all(), [
            'id_proyecto' => ['required', 'integer', 'min:1'],
            'repositorios_ids' => ['required', 'array', 'min:1'],
            'repositorios_ids.*' => ['integer', 'min:1'],
            'participacion_data' => ['nullable', 'array'],
            'participacion_data.rol' => ['nullable', 'string', 'max:100'],
            'participacion_data.descripcion_aporte' => ['nullable', 'string', 'max:600'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos invalidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $result = $this->githubRepositorySyncService->attachDetectedReposToProject(
            (int) $user->id_usuario,
            (int) $data['id_proyecto'],
            $data['repositorios_ids'],
            $data['participacion_data'] ?? [],
        );

        // seccion para notificar
        if (in_array(($result['status'] ?? null), ['success', 'linked_existing_project'], true)) {
            $idProyectoNotificacion = (int) (
                $result['id_proyecto']
                ?? $result['existing_project_id']
                ?? $data['id_proyecto']
            );

            if ($idProyectoNotificacion > 0) {
                $this->proyectoNotificacionGuardadoService->notificarNuevoParticipante(
                    idProyecto: $idProyectoNotificacion,
                    idUsuarioNuevo: (int) $user->id_usuario,
                    idUsuarioActor: (int) $user->id_usuario
                );
            }
        }
        // fin seccion para notificar

        return match ($result['status'] ?? 'error') {
            'success' => response()->json($result),
            'linked_existing_project' => response()->json($result),
            'invalid_payload' => response()->json($result, 422),
            'participacion_not_found' => response()->json($result, 404),
            'repos_not_found' => response()->json($result, 404),
            'repo_requires_validation' => response()->json($result, $result['http_status'] ?? 403),
            'already_assigned' => response()->json($result, 409),
            default => response()->json($result, 500),
        };
    }
}
