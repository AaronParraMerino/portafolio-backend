<?php

namespace App\Services\api;

use App\Models\CuentaOauth;
use App\Models\ParticipacionRepositorio;
use App\Models\ProyectoRepositorio;
use App\Models\RepositorioGithub;
use App\Models\UsuarioRepositorioValidacion;
use App\Services\api\Auth\GitlabOAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GitlabRepositorySyncService
{
    private const MAX_DETAILED_REPOS_PER_SYNC = 0;
    private const MAX_PROJECT_PAGES_PER_SYNC = 3;

    public function __construct(private readonly GitlabOAuthService $gitlabOAuthService)
    {
    }

    public function syncForUsuario(int $usuarioId): array
    {
        $cuentaGitlab = CuentaOauth::where('usuario_id', $usuarioId)
            ->where('provider', 'gitlab')
            ->first();

        if (! $cuentaGitlab) {
            return [
                'status' => 'not_linked',
                'message' => 'No hay una cuenta de GitLab vinculada para este usuario.',
            ];
        }

        $token = $this->usableAccessToken($cuentaGitlab);

        if (($token['status'] ?? 'error') !== 'success') {
            return $token;
        }

        $projectsResponse = $this->fetchAllGitlabProjects($token['access_token']);

        if (($projectsResponse['status'] ?? null) === 'invalid_token' && ! ($token['refreshed'] ?? false)) {
            $token = $this->refreshAccessToken($cuentaGitlab, $token['access_token']);

            if (($token['status'] ?? 'error') !== 'success') {
                return $token;
            }

            $projectsResponse = $this->fetchAllGitlabProjects($token['access_token']);
        }

        if (($projectsResponse['status'] ?? 'error') !== 'success') {
            return $projectsResponse;
        }

        $projects = $projectsResponse['repos'];
        $created = 0;
        $updated = 0;
        $detailsUpdated = 0;
        $detailsSkipped = 0;

        foreach ($projects as $project) {
            $url = trim((string) ($project['web_url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $providerRepoId = $project['id'] ?? null;

            $proyectoRepo = ProyectoRepositorio::withTrashed()
                ->when($providerRepoId, function ($query) use ($providerRepoId) {
                    $query->whereHas('github', fn ($detailQuery) => $detailQuery->where('github_repo_id', $providerRepoId));
                }, function ($query) use ($url) {
                    $query->whereRaw('lower(url_repositorio) = ?', [strtolower($url)]);
                })
                ->where('proveedor', 'gitlab')
                ->first();

            $isNew = false;

            if (! $proyectoRepo) {
                $proyectoRepo = new ProyectoRepositorio();
                $isNew = true;
            }

            if ($proyectoRepo->exists && $proyectoRepo->trashed()) {
                $proyectoRepo->restore();
                $proyectoRepo->id_proyecto = null;
            }

            $proyectoRepo->id_proyecto = $proyectoRepo->id_proyecto;
            $proyectoRepo->nombre = $project['name'] ?? $project['path'] ?? $proyectoRepo->nombre;
            $proyectoRepo->tipo = $this->resolveRepoType($project);
            $proyectoRepo->proveedor = 'gitlab';
            $proyectoRepo->url_repositorio = $url;
            $proyectoRepo->descripcion = $project['description'] ?? null;
            $proyectoRepo->save();

            $remoteRepo = RepositorioGithub::firstOrNew([
                'id_proyecto_repositorio' => $proyectoRepo->id_proyecto_repositorio,
            ]);

            $shouldRefreshDetails = $this->shouldRefreshDetails($remoteRepo, $project);

            $remoteRepo->github_repo_id = $project['id'] ?? null;
            $remoteRepo->github_owner = $project['namespace']['full_path'] ?? $project['namespace']['path'] ?? null;
            $remoteRepo->github_repo_name = $project['path'] ?? $project['name'] ?? null;
            $remoteRepo->github_description = $project['description'] ?? null;
            $remoteRepo->github_homepage = $project['web_url'] ?? null;
            $remoteRepo->default_branch = $project['default_branch'] ?? null;
            $remoteRepo->is_private = $this->dbBool(($project['visibility'] ?? '') !== 'public');
            $remoteRepo->is_fork = $this->dbBool(! empty($project['forked_from_project']));
            $remoteRepo->is_archived = $this->dbBool($project['archived'] ?? false);
            $remoteRepo->stars_count = (int) ($project['star_count'] ?? 0);
            $remoteRepo->forks_count = (int) ($project['forks_count'] ?? 0);
            $remoteRepo->open_issues_count = (int) ($project['open_issues_count'] ?? 0);
            $remoteRepo->last_push_at = $project['last_activity_at'] ?? null;
            $remoteRepo->repo_created_at = $project['created_at'] ?? null;
            $remoteRepo->repo_updated_at = $project['last_activity_at'] ?? null;

            if ($shouldRefreshDetails && $detailsUpdated < self::MAX_DETAILED_REPOS_PER_SYNC) {
                $this->fillGitlabRepoDetails($remoteRepo, $project, $token['access_token']);
                $detailsUpdated++;
            } elseif ($shouldRefreshDetails) {
                $detailsSkipped++;
            }

            $remoteRepo->sync_status = 'sincronizado';
            $remoteRepo->sync_error = null;
            $remoteRepo->last_sync_at = now();
            $remoteRepo->save();

            $permissions = $this->extractPermissions($project);
            $relation = $this->resolveGitlabRelation($permissions);
            $isOwner = $relation === 'owner';

            UsuarioRepositorioValidacion::updateOrCreate(
                [
                    'id_usuario' => $usuarioId,
                    'id_repositorio_github' => $remoteRepo->id_repositorio_github,
                ],
                [
                    'id_cuenta_oauth' => $cuentaGitlab->id_cuenta_oauth,
                    'relacion_github' => $relation,
                    'es_propietario' => $this->dbBool($isOwner),
                    'validado' => $this->dbBool(true),
                    'validado_at' => now(),
                    'ultima_verificacion_at' => now(),
                    'permisos_github' => $permissions,
                    'detalle_validacion' => 'Validado por sincronizacion automatica desde GitLab.',
                ]
            );

            if ($isNew) {
                $created++;
            } else {
                $updated++;
            }
        }

        $cuentaGitlab->token_updated_at = now();
        $cuentaGitlab->save();

        return [
            'status' => 'success',
            'message' => 'Repositorios GitLab sincronizados correctamente.',
            'stats' => [
                'total_remotos' => count($projects),
                'creados' => $created,
                'actualizados' => $updated,
                'detalles_actualizados' => $detailsUpdated,
                'detalles_omitidos_por_limite' => $detailsSkipped,
            ],
        ];
    }

    public function fetchRepoLanguagesForUsuario(int $usuarioId, string $repoUrl): array
    {
        $project = $this->parseGitlabProjectUrl($repoUrl);

        if (! $project) {
            return [
                'status' => 'invalid_payload',
                'message' => 'La URL del repositorio de GitLab no es valida.',
            ];
        }

        $cuentaGitlab = CuentaOauth::where('usuario_id', $usuarioId)
            ->where('provider', 'gitlab')
            ->first();

        if (! $cuentaGitlab) {
            return [
                'status' => 'not_linked',
                'message' => 'Necesita vincular GitLab para consultar este repositorio.',
            ];
        }

        $token = $this->usableAccessToken($cuentaGitlab);

        if (($token['status'] ?? 'error') !== 'success') {
            return $token;
        }

        $response = $this->gitlabRequest($token['access_token'])
            ->get('https://gitlab.com/api/v4/projects/' . rawurlencode($project['path']) . '/languages');

        if ($response->status() === 401 && ! ($token['refreshed'] ?? false)) {
            $token = $this->refreshAccessToken($cuentaGitlab, $token['access_token']);

            if (($token['status'] ?? 'error') !== 'success') {
                return $token;
            }

            $response = $this->gitlabRequest($token['access_token'])
                ->get('https://gitlab.com/api/v4/projects/' . rawurlencode($project['path']) . '/languages');
        }

        if ($response->status() === 401) {
            return [
                'status' => 'invalid_token',
                'message' => 'La vinculacion GitLab no es valida. Vuelve a vincular la cuenta.',
            ];
        }

        if ($response->status() === 404) {
            return [
                'status' => 'repo_not_found_or_no_access',
                'message' => 'No se encontro el repositorio en GitLab o no tienes acceso.',
            ];
        }

        if (! $response->ok()) {
            return [
                'status' => 'github_error',
                'message' => 'No se pudo consultar lenguajes del repositorio en GitLab.',
                'github_status' => $response->status(),
                'github_body' => $response->json(),
            ];
        }

        $payload = $response->json();

        return [
            'status' => 'success',
            'owner' => $project['owner'],
            'repo' => $project['name'],
            'authenticated' => true,
            'languages' => is_array($payload) ? array_values(array_keys($payload)) : [],
        ];
    }

    public function attachDetectedReposToProject(int $usuarioId, int $idProyecto, array $repositoriosIds, array $participacionData = []): array
    {
        $ids = collect($repositoriosIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [
                'status' => 'invalid_payload',
                'message' => 'Selecciona al menos un repositorio GitLab.',
            ];
        }

        $repos = ProyectoRepositorio::query()
            ->with('github')
            ->whereIn('id_proyecto_repositorio', $ids->all())
            ->where('proveedor', 'gitlab')
            ->get();

        if ($repos->count() !== $ids->count()) {
            return [
                'status' => 'repos_not_found',
                'message' => 'Uno o mas repositorios detectados no existen o no son de GitLab.',
            ];
        }

        $assignedElsewhere = $repos
            ->filter(fn ($repo) => ! is_null($repo->id_proyecto) && (int) $repo->id_proyecto !== $idProyecto);

        if ($assignedElsewhere->isNotEmpty()) {
            $targetProjectId = (int) $assignedElsewhere->first()->id_proyecto;
            $link = $this->linkUsuarioToExistingProjectByRepo($usuarioId, $targetProjectId, $participacionData);

            return [
                ...$link,
                'status' => ($link['status'] ?? null) === 'success' ? 'linked_existing_project' : ($link['status'] ?? 'error'),
                'message' => ($link['status'] ?? null) === 'success'
                    ? 'El repositorio ya pertenecia a otro proyecto; se vinculo tu participacion a ese proyecto existente.'
                    : ($link['message'] ?? 'No se pudo vincular la participacion al proyecto existente.'),
            ];
        }

        $participacion = $this->ensureParticipacionForProject($usuarioId, $idProyecto, $participacionData);

        if (! $participacion) {
            return [
                'status' => 'participacion_not_found',
                'message' => 'No existe participacion del usuario para el proyecto indicado.',
            ];
        }

        $this->attachReposToParticipacion($usuarioId, $participacion, $repos, $idProyecto, $participacionData);

        return [
            'status' => 'success',
            'message' => 'Repositorios GitLab vinculados al proyecto correctamente.',
            'id_proyecto' => $idProyecto,
            'id_participacion' => (int) $participacion->id_participacion,
            'repositorios_vinculados' => $repos->count(),
        ];
    }

    public function linkUsuarioToExistingProjectByRepo(int $usuarioId, int $idProyecto, array $participacionData = []): array
    {
        $repos = ProyectoRepositorio::query()
            ->with('github')
            ->where('id_proyecto', $idProyecto)
            ->where('proveedor', 'gitlab')
            ->get();

        $repoIds = $repos
            ->map(fn ($repo) => $repo->github?->id_repositorio_github)
            ->filter()
            ->values();

        $hasValidatedRepo = $repoIds->isNotEmpty()
            && UsuarioRepositorioValidacion::query()
                ->where('id_usuario', $usuarioId)
                ->whereIn('id_repositorio_github', $repoIds->all())
                ->whereRaw('validado = TRUE')
                ->exists();

        if (! $hasValidatedRepo && ! $this->projectAllowsUnvalidatedParticipants($idProyecto)) {
            return [
                'status' => 'repo_requires_validation',
                'http_status' => 403,
                'id_proyecto' => $idProyecto,
                'message' => 'Este repositorio ya pertenece a un proyecto que requiere validacion GitLab para unirse.',
            ];
        }

        $participacion = $this->ensureParticipacionForProject($usuarioId, $idProyecto, $participacionData);

        if (! $participacion) {
            return [
                'status' => 'project_not_found',
                'message' => 'No se encontro el proyecto vinculado al repositorio.',
            ];
        }

        $this->attachReposToParticipacion($usuarioId, $participacion, $repos, $idProyecto, $participacionData);

        return [
            'status' => 'success',
            'message' => 'Participacion vinculada al proyecto existente por repositorio GitLab.',
            'id_proyecto' => $idProyecto,
            'id_participacion' => (int) $participacion->id_participacion,
        ];
    }

    private function attachReposToParticipacion(int $usuarioId, object $participacion, $repos, int $idProyecto, array $participacionData = []): void
    {
        $repoIds = $repos
            ->map(fn ($repo) => $repo->github?->id_repositorio_github)
            ->filter()
            ->values();

        $validaciones = UsuarioRepositorioValidacion::query()
            ->where('id_usuario', $usuarioId)
            ->whereIn('id_repositorio_github', $repoIds->all())
            ->get()
            ->keyBy('id_repositorio_github');

        DB::transaction(function () use ($repos, $validaciones, $participacion, $idProyecto, $participacionData) {
            $participacionUpdate = array_filter([
                'rol' => isset($participacionData['rol']) ? trim((string) $participacionData['rol']) : null,
                'descripcion_aporte' => isset($participacionData['descripcion_aporte']) ? trim((string) $participacionData['descripcion_aporte']) : null,
                'updated_at' => now(),
            ], fn ($value) => ! is_null($value) && $value !== '');

            if (! empty($participacionUpdate)) {
                DB::table('participaciones')
                    ->where('id_participacion', $participacion->id_participacion)
                    ->update($participacionUpdate);
            }

            foreach ($repos as $repo) {
                $validacion = $validaciones->get($repo->github?->id_repositorio_github);
                $isValid = (bool) ($validacion?->validado ?? false);

                $repo->id_proyecto = $idProyecto;
                $repo->save();

                ParticipacionRepositorio::updateOrCreate(
                    [
                        'id_participacion' => $participacion->id_participacion,
                        'id_proyecto_repositorio' => $repo->id_proyecto_repositorio,
                    ],
                    [
                        'validado' => $this->dbBool($isValid),
                        'es_propietario' => $this->dbBool($validacion?->es_propietario ?? false),
                        'validado_at' => $isValid ? now() : null,
                    ]
                );
            }

            $hayValidado = ParticipacionRepositorio::query()
                ->where('id_participacion', $participacion->id_participacion)
                ->whereRaw('validado = TRUE')
                ->exists();

            DB::table('participaciones')
                ->where('id_participacion', $participacion->id_participacion)
                ->update([
                    'participacion_validada' => $this->dbBool($hayValidado),
                    'updated_at' => now(),
                ]);
        });
    }

    private function ensureParticipacionForProject(int $usuarioId, int $idProyecto, array $participacionData = []): ?object
    {
        $projectExists = DB::table('proyectos')
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->exists();

        if (! $projectExists) {
            return null;
        }

        $participacion = DB::table('participaciones')
            ->where('id_usuario', $usuarioId)
            ->where('id_proyecto', $idProyecto)
            ->first();

        $data = [
            'rol' => isset($participacionData['rol']) && trim((string) $participacionData['rol']) !== ''
                ? trim((string) $participacionData['rol'])
                : ($participacion->rol ?? 'colaborador'),
            'descripcion_aporte' => isset($participacionData['descripcion_aporte']) && trim((string) $participacionData['descripcion_aporte']) !== ''
                ? trim((string) $participacionData['descripcion_aporte'])
                : ($participacion->descripcion_aporte ?? null),
            'visibilidad' => $participacion->visibilidad ?? 'publico',
            'estado_participacion' => 'activo',
            'deleted_at' => null,
            'updated_at' => now(),
        ];

        if ($participacion) {
            DB::table('participaciones')
                ->where('id_participacion', $participacion->id_participacion)
                ->update($data);
        } else {
            $idParticipacion = DB::table('participaciones')->insertGetId([
                'id_usuario' => $usuarioId,
                'id_proyecto' => $idProyecto,
                'es_propietario' => $this->dbBool(false),
                'fecha_inicio' => null,
                'fecha_fin' => null,
                'created_at' => now(),
                ...$data,
            ], 'id_participacion');

            $participacion = DB::table('participaciones')->where('id_participacion', $idParticipacion)->first();
        }

        return DB::table('participaciones')
            ->where('id_usuario', $usuarioId)
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->first();
    }

    private function fillGitlabRepoDetails(RepositorioGithub $remoteRepo, array $project, string $accessToken): void
    {
        $projectId = $project['id'] ?? null;

        if (! $projectId) {
            return;
        }

        $commits = $this->gitlabRequest($accessToken)
            ->get("https://gitlab.com/api/v4/projects/{$projectId}/repository/commits", [
                'per_page' => 1,
                'ref_name' => $project['default_branch'] ?? null,
            ]);

        if ($commits->ok()) {
            $payload = $commits->json();
            $latest = is_array($payload) ? ($payload[0] ?? null) : null;
            $remoteRepo->commits_count = (int) ($commits->header('X-Total') ?: (is_array($payload) ? count($payload) : 0));

            if (is_array($latest)) {
                $remoteRepo->last_commit_message = $latest['message'] ?? $latest['title'] ?? null;
                $remoteRepo->last_commit_date = $latest['committed_date'] ?? $latest['created_at'] ?? null;
            }
        }

        $contributors = $this->gitlabRequest($accessToken)
            ->get("https://gitlab.com/api/v4/projects/{$projectId}/repository/contributors", [
                'per_page' => 1,
            ]);

        if ($contributors->ok()) {
            $payload = $contributors->json();
            $remoteRepo->contributors_count = (int) ($contributors->header('X-Total') ?: (is_array($payload) ? count($payload) : 0));
        }

        if (! empty($project['default_branch'])) {
            $readme = $this->gitlabRequest($accessToken)
                ->get("https://gitlab.com/api/v4/projects/{$projectId}/repository/files/README.md/raw", [
                    'ref' => $project['default_branch'],
                ]);

            if ($readme->ok()) {
                $remoteRepo->readme_resumen = $this->formatReadmeResumen($readme->body());
            }
        }
    }

    private function fetchAllGitlabProjects(string $accessToken): array
    {
        $projects = [];
        $page = 1;

        do {
            $response = $this->gitlabRequest($accessToken)
                ->get('https://gitlab.com/api/v4/projects', [
                    'membership' => 'true',
                    'simple' => 'false',
                    'order_by' => 'last_activity_at',
                    'sort' => 'desc',
                    'per_page' => 100,
                    'page' => $page,
                ]);

            if ($response->status() === 401) {
                return [
                    'status' => 'invalid_token',
                    'message' => 'La vinculacion GitLab no es valida. Vuelve a vincular la cuenta.',
                ];
            }

            if ($response->status() === 403) {
                return [
                    'status' => 'insufficient_scope',
                    'message' => 'La cuenta GitLab vinculada no tiene permisos para leer proyectos. Desvincula y vuelve a vincular GitLab.',
                ];
            }

            if (! $response->ok()) {
                return [
                    'status' => 'github_error',
                    'message' => 'No se pudo consultar repositorios en GitLab.',
                    'github_status' => $response->status(),
                    'github_body' => $response->json(),
                ];
            }

            $chunk = $response->json();
            if (! is_array($chunk)) {
                $chunk = [];
            }

            $projects = array_merge($projects, $chunk);
            $page++;
        } while (count($chunk) === 100 && $page <= self::MAX_PROJECT_PAGES_PER_SYNC);

        return [
            'status' => 'success',
            'repos' => $projects,
        ];
    }

    private function usableAccessToken(CuentaOauth $cuentaGitlab): array
    {
        if (! $cuentaGitlab->access_token) {
            return [
                'status' => 'missing_token',
                'message' => 'La cuenta vinculada no tiene token de sincronizacion.',
            ];
        }

        if ($cuentaGitlab->token_expires_at && $cuentaGitlab->token_expires_at->lte(now()->addMinute())) {
            return $this->refreshAccessToken($cuentaGitlab, $cuentaGitlab->access_token);
        }

        return [
            'status' => 'success',
            'access_token' => $cuentaGitlab->access_token,
            'refreshed' => false,
        ];
    }

    private function refreshAccessToken(CuentaOauth $cuentaGitlab, ?string $staleAccessToken = null): array
    {
        $result = $this->gitlabOAuthService->refreshAccessToken($cuentaGitlab, $staleAccessToken);

        if (($result['status'] ?? 'error') !== 'success') {
            return $result;
        }

        return [
            ...$result,
            'refreshed' => true,
        ];
    }

    private function gitlabRequest(string $accessToken): \Illuminate\Http\Client\PendingRequest
    {
        return Http::connectTimeout(3)
            ->timeout(6)
            ->withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Accept' => 'application/json',
                'User-Agent' => 'portafolio-app',
            ]);
    }

    private function shouldRefreshDetails(RepositorioGithub $remoteRepo, array $project): bool
    {
        if (! $remoteRepo->exists) {
            return true;
        }

        if (
            is_null($remoteRepo->commits_count)
            || is_null($remoteRepo->contributors_count)
            || is_null($remoteRepo->last_commit_message)
            || is_null($remoteRepo->last_commit_date)
            || is_null($remoteRepo->readme_resumen)
        ) {
            return true;
        }

        $remoteUpdatedAt = $this->normalizeTimestamp($project['last_activity_at'] ?? null);
        $localUpdatedAt = $this->normalizeTimestamp($remoteRepo->repo_updated_at);

        return $remoteUpdatedAt && $localUpdatedAt && $remoteUpdatedAt !== $localUpdatedAt;
    }

    private function parseGitlabProjectUrl(string $repoUrl): ?array
    {
        $parts = parse_url(trim($repoUrl));

        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($host, ['gitlab.com', 'www.gitlab.com'], true)) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            return null;
        }

        $path = preg_replace('/\.git$/i', '', $path) ?? $path;
        $segments = array_values(array_filter(explode('/', $path), fn ($segment) => $segment !== ''));

        if (count($segments) < 2) {
            return null;
        }

        return [
            'path' => implode('/', $segments),
            'owner' => $segments[0],
            'name' => $segments[count($segments) - 1],
        ];
    }

    private function extractPermissions(array $project): array
    {
        return [
            'permissions' => $project['permissions'] ?? null,
            'visibility' => $project['visibility'] ?? null,
            'namespace' => $project['namespace']['full_path'] ?? null,
        ];
    }

    private function resolveGitlabRelation(array $permissions): string
    {
        $projectAccess = (int) ($permissions['permissions']['project_access']['access_level'] ?? 0);
        $groupAccess = (int) ($permissions['permissions']['group_access']['access_level'] ?? 0);
        $access = max($projectAccess, $groupAccess);

        if ($access >= 50) {
            return 'owner';
        }

        if ($access >= 40) {
            return 'maintainer';
        }

        if ($access >= 30) {
            return 'collaborator';
        }

        if ($access >= 20) {
            return 'contributor';
        }

        if ($access >= 10) {
            return 'member';
        }

        return 'unknown';
    }

    private function resolveRepoType(array $project): string
    {
        $name = strtolower((string) ($project['name'] ?? ''));
        $description = strtolower((string) ($project['description'] ?? ''));

        if (str_contains($name, 'front') || str_contains($description, 'frontend')) {
            return 'frontend';
        }

        if (str_contains($name, 'back') || str_contains($description, 'backend') || str_contains($description, 'api')) {
            return 'backend';
        }

        if (str_contains($description, 'mobile') || str_contains($description, 'android') || str_contains($description, 'ios')) {
            return 'mobile';
        }

        return 'otro';
    }

    private function projectAllowsUnvalidatedParticipants(int $idProyecto): bool
    {
        $value = DB::table('proyecto_configuraciones')
            ->where('id_proyecto', $idProyecto)
            ->value('permitir_participantes_sin_validacion');

        return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : null;
    }

    private function formatReadmeResumen(mixed $content): ?string
    {
        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        $plain = preg_replace('/[`*_>#\[\]()~-]+/', ' ', $content) ?? $content;
        $plain = preg_replace('/\s+/', ' ', $plain) ?? $plain;
        $plain = trim($plain);

        return $plain === '' ? null : mb_substr($plain, 0, 1200);
    }

    private function dbBool(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }
}
