<?php

namespace App\Http\Controllers\Api\Auth;

use App\Models\ProyectoRepositorio;
use App\Models\UsuarioRepositorioValidacion;
use App\Services\api\Auth\OAuthProviderAuthorizationService;
use App\Services\api\AuthService;
use App\Services\api\GithubRepositorySyncService;
use App\Services\api\SeccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GithubAuthController extends ProviderOAuthController
{
    public function __construct(
        AuthService $authService,
        OAuthProviderAuthorizationService $authorizationService,
        SeccionService $seccionService,
        private readonly GithubRepositorySyncService $githubRepositorySyncService,
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

        if ($refresh) {
            $sync = $this->githubRepositorySyncService->syncForUsuario((int) $user->id_usuario);

            if (($sync['status'] ?? 'error') !== 'success') {
                return match ($sync['status'] ?? 'error') {
                    'not_linked' => response()->json($sync, 404),
                    'missing_token' => response()->json($sync, 422),
                    'invalid_token' => response()->json($sync, 401),
                    default => response()->json($sync, 502),
                };
            }
        }

        $repos = ProyectoRepositorio::query()
            ->with('github')
            ->where('proveedor', 'github')
            ->whereHas('github')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($repo) use ($user) {
                $github = $repo->github;

                $validacion = UsuarioRepositorioValidacion::query()
                    ->where('id_usuario', $user->id_usuario)
                    ->where('id_repositorio_github', $github->id_repositorio_github)
                    ->first();

                $participacion = null;
                $proyecto = null;

                if ($repo->id_proyecto) {
                    $participacion = DB::table('participaciones')
                        ->where('id_usuario', $user->id_usuario)
                        ->where('id_proyecto', $repo->id_proyecto)
                        ->whereNull('deleted_at')
                        ->first();

                    $proyecto = DB::table('proyectos')
                        ->where('id_proyecto', $repo->id_proyecto)
                        ->whereNull('deleted_at')
                        ->select('id_proyecto', 'titulo', 'descripcion', 'estado_publicacion', 'estado_desarrollo')
                        ->first();
                }

                $repoEnUso = ! is_null($repo->id_proyecto);
                $validado = (bool) ($validacion->validado ?? false);
                $permiteSinValidacion = false;

                if ($repoEnUso) {
                    $permiteSinValidacion = in_array(strtolower((string) DB::table('proyecto_configuraciones')
                        ->where('id_proyecto', $repo->id_proyecto)
                        ->value('permitir_participantes_sin_validacion')), ['1', 't', 'true', 'yes', 'on'], true);
                }

                if (! $validado && ! ($repoEnUso && $permiteSinValidacion)) {
                    return null;
                }

                if ($repoEnUso && ($participacion || ! $proyecto || (! $validado && ! $permiteSinValidacion))) {
                    return null;
                }

                return [
                    'id_proyecto_repositorio' => $repo->id_proyecto_repositorio,
                    'id_proyecto' => $repo->id_proyecto,
                    'nombre' => $repo->nombre,
                    'url_repositorio' => $repo->url_repositorio,
                    'descripcion' => $repo->descripcion,
                    'tipo' => $repo->tipo,
                    'estado_vinculacion' => $repoEnUso ? 'en_uso' : 'libre',
                    'puede_unirse' => $repoEnUso && ($validado || $permiteSinValidacion) && ! $participacion,
                    'proyecto' => $proyecto ? [
                        'id_proyecto' => $proyecto->id_proyecto,
                        'titulo' => $proyecto->titulo,
                        'descripcion' => $proyecto->descripcion,
                        'estado_publicacion' => $proyecto->estado_publicacion,
                        'estado_desarrollo' => $proyecto->estado_desarrollo,
                    ] : null,
                    'repo_github' => [
                        'id_repositorio_github' => $github->id_repositorio_github,
                        'github_repo_id' => $github->github_repo_id,
                        'owner' => $github->github_owner,
                        'repo_name' => $github->github_repo_name,
                        'is_private' => $github->is_private,
                        'is_archived' => $github->is_archived,
                        'last_push_at' => $github->last_push_at,
                        'stars_count' => $github->stars_count,
                    ],
                    'validacion' => [
                        'validado' => (bool) ($validacion->validado ?? false),
                        'relacion_github' => $validacion->relacion_github ?? 'unknown',
                        'es_propietario' => (bool) ($validacion->es_propietario ?? false),
                        'ultima_verificacion_at' => $validacion->ultima_verificacion_at ?? null,
                    ],
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $repos,
        ]);
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
