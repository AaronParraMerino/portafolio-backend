<?php

namespace App\Services\api;

use App\Models\CuentaOauth;
use App\Models\ParticipacionRepositorio;
use App\Models\ProyectoRepositorio;
use App\Models\RepositorioGithub;
use App\Models\UsuarioRepositorioValidacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GithubRepositorySyncService
{
    public function findExistingProjectForValidatedRepoUrls(int $usuarioId, array $repoUrls): ?int
    {
        foreach ($repoUrls as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            $metadata = $this->getCachedGithubRepoMetadata(trim($url));
            if (! $metadata) {
                $result = $this->fetchGithubRepoMetadataForUsuario($usuarioId, trim($url));
                if (($result['status'] ?? null) !== 'success') {
                    continue;
                }
                $metadata = $result['repo'];
            }

            $githubRepoId = $metadata['id'] ?? null;
            if (! $githubRepoId) {
                continue;
            }

            $repo = ProyectoRepositorio::query()
                ->with('github')
                ->whereHas('github', fn ($query) => $query->where('github_repo_id', $githubRepoId))
                ->whereNotNull('id_proyecto')
                ->where('proveedor', 'github')
                ->first();

            if (! $repo?->github?->id_repositorio_github) {
                continue;
            }

            $validado = UsuarioRepositorioValidacion::query()
                ->where('id_usuario', $usuarioId)
                ->where('id_repositorio_github', $repo->github->id_repositorio_github)
                ->where('validado', $this->dbBool(true))
                ->exists();

            if ($validado) {
                return (int) $repo->id_proyecto;
            }
        }

        return null;
    }

    public function linkUsuarioToExistingProjectByRepo(int $usuarioId, int $idProyecto, array $participacionData = []): array
    {
        $participacion = $this->ensureParticipacionForProject($usuarioId, $idProyecto, $participacionData);
        if (! $participacion) {
            return [
                'status' => 'project_not_found',
                'message' => 'No se encontro el proyecto vinculado al repositorio.',
            ];
        }

        $repos = ProyectoRepositorio::query()
            ->with('github')
            ->where('id_proyecto', $idProyecto)
            ->where('proveedor', 'github')
            ->get();

        $this->syncParticipacionRepositorios($usuarioId, $participacion, $repos);

        return [
            'status' => 'success',
            'message' => 'Participacion vinculada al proyecto existente por repositorio GitHub.',
            'id_proyecto' => $idProyecto,
            'id_participacion' => (int) $participacion->id_participacion,
        ];
    }

    public function fetchRepoLanguagesForUsuario(int $usuarioId, string $repoUrl): array
    {
        $repo = $this->parseGithubRepoUrl($repoUrl);

        if (! $repo) {
            return [
                'status' => 'invalid_payload',
                'message' => 'La URL del repositorio de GitHub no es válida.',
            ];
        }

        $publicResponse = Http::timeout(20)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'portafolio-app',
            ])
            ->get("https://api.github.com/repos/{$repo['owner']}/{$repo['name']}/languages");

        if ($publicResponse->ok()) {
            return $this->formatLanguagesResponse($repo, $publicResponse->json(), false);
        }

        $cuentaGithub = CuentaOauth::where('usuario_id', $usuarioId)
            ->where('provider', 'github')
            ->first();

        if (! $cuentaGithub) {
            return [
                'status' => 'private_or_no_access',
                'message' => 'Necesita vincular cuenta, actualmente no cuenta con los permisos.',
            ];
        }

        if (! $cuentaGithub->access_token) {
            return [
                'status' => 'private_or_no_access',
                'message' => 'La cuenta vinculada no tiene token de sincronización.',
            ];
        }

        $response = Http::timeout(20)
            ->withHeaders([
                'Authorization' => "Bearer {$cuentaGithub->access_token}",
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'portafolio-app',
            ])
            ->get("https://api.github.com/repos/{$repo['owner']}/{$repo['name']}/languages");

        if ($response->status() === 401) {
            return [
                'status' => 'invalid_token',
                'message' => 'Token GitHub inválido o expirado, vuelve a vincular la cuenta.',
            ];
        }

        if ($response->status() === 404) {
            return [
                'status' => 'private_or_no_access',
                'message' => 'No se encontró el repositorio en GitHub o no tienes acceso.',
            ];
        }

        if (! $response->ok()) {
            return [
                'status' => 'github_error',
                'message' => 'No se pudo consultar lenguajes del repositorio en GitHub.',
                'github_status' => $response->status(),
                'github_body' => $response->json(),
            ];
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            $payload = [];
        }

        return [
            'status' => 'success',
            'owner' => $repo['owner'],
            'repo' => $repo['name'],
            'authenticated' => true,
            'languages' => array_values(array_keys($payload)),
        ];
    }

    private function formatLanguagesResponse(array $repo, mixed $payload, bool $authenticated): array
    {
        if (! is_array($payload)) {
            $payload = [];
        }

        return [
            'status' => 'success',
            'owner' => $repo['owner'],
            'repo' => $repo['name'],
            'authenticated' => $authenticated,
            'languages' => array_values(array_keys($payload)),
        ];
    }

    public function syncProjectRepoUrlsForUsuario(int $usuarioId, int $idProyecto, array $repoUrls): array
    {
        $urls = collect($repoUrls)
            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
            ->map(fn ($url) => trim((string) $url))
            ->unique()
            ->values();

        $validatedRepos = [];

        foreach ($urls as $url) {
            $cachedRepo = $this->getCachedGithubRepoMetadata($url);

            if ($cachedRepo) {
                $validatedRepos[] = [
                    'url' => $url,
                    'repo' => $cachedRepo,
                ];
                continue;
            }

            $githubRepo = $this->fetchGithubRepoMetadataForUsuario($usuarioId, $url);

            if (($githubRepo['status'] ?? 'error') !== 'success') {
                return $githubRepo;
            }

            $validatedRepos[] = [
                'url' => $url,
                'repo' => $githubRepo['repo'],
            ];
        }

        $reposParaDesvincular = ProyectoRepositorio::query()
            ->where('id_proyecto', $idProyecto)
            ->where('proveedor', 'github')
            ->when($urls->isNotEmpty(), fn ($query) => $query->whereNotIn('url_repositorio', $urls->all()))
            ->get();

        if ($reposParaDesvincular->isNotEmpty()) {
            $repoIds = $reposParaDesvincular
                ->pluck('id_proyecto_repositorio')
                ->filter()
                ->values();

            $participacion = DB::table('participaciones')
                ->where('id_usuario', $usuarioId)
                ->where('id_proyecto', $idProyecto)
                ->whereNull('deleted_at')
                ->first();

            DB::table('participacion_repositorios')
                ->whereIn('id_proyecto_repositorio', $repoIds->all())
                ->delete();

            ProyectoRepositorio::query()
                ->whereIn('id_proyecto_repositorio', $repoIds->all())
                ->update([
                    'id_proyecto' => null,
                    'updated_at' => now(),
                ]);

            if ($participacion) {
                $hayValidado = ParticipacionRepositorio::query()
                    ->where('id_participacion', $participacion->id_participacion)
                    ->where('validado', $this->dbBool(true))
                    ->exists();

                DB::table('participaciones')
                    ->where('id_participacion', $participacion->id_participacion)
                    ->update([
                        'participacion_validada' => $this->dbBool($hayValidado),
                        'updated_at' => now(),
                    ]);
            }
        }

        if ($urls->isEmpty()) {
            return [
                'status' => 'success',
                'repositorios_sincronizados' => 0,
            ];
        }

        foreach ($validatedRepos as $validatedRepo) {
            $saveResult = $this->saveGithubRepoForProject($idProyecto, $validatedRepo['url'], $validatedRepo['repo']);

            if (($saveResult['status'] ?? null) === 'linked_existing_project') {
                $this->linkUsuarioToExistingProjectByRepo($usuarioId, (int) $saveResult['id_proyecto']);
                continue;
            }

            if (($saveResult['status'] ?? 'error') !== 'success') {
                return $saveResult;
            }
        }

        return [
            'status' => 'success',
            'repositorios_sincronizados' => count($validatedRepos),
        ];
    }

    private function getCachedGithubRepoMetadata(string $url): ?array
    {
        $proyectoRepo = ProyectoRepositorio::withTrashed()
            ->with('github')
            ->whereRaw('lower(url_repositorio) = ?', [strtolower($url)])
            ->where('proveedor', 'github')
            ->first();

        $github = $proyectoRepo?->github;
        if (! $github) {
            return null;
        }

        return [
            'id' => $github->github_repo_id,
            'owner' => ['login' => $github->github_owner],
            'name' => $github->github_repo_name ?: $proyectoRepo->nombre,
            'description' => $github->github_description ?? $proyectoRepo->descripcion,
            'homepage' => $github->github_homepage,
            'default_branch' => $github->default_branch,
            'private' => (bool) $github->is_private,
            'fork' => (bool) $github->is_fork,
            'archived' => (bool) $github->is_archived,
            'stargazers_count' => (int) ($github->stars_count ?? 0),
            'forks_count' => (int) ($github->forks_count ?? 0),
            'open_issues_count' => (int) ($github->open_issues_count ?? 0),
            'pushed_at' => $github->last_push_at,
            'created_at' => $github->repo_created_at,
            'updated_at' => $github->repo_updated_at,
        ];
    }

    private function fetchGithubRepoMetadataForUsuario(int $usuarioId, string $repoUrl): array
    {
        $repo = $this->parseGithubRepoUrl($repoUrl);

        if (! $repo) {
            return [
                'status' => 'invalid_payload',
                'http_status' => 422,
                'message' => 'La URL del repositorio de GitHub no es valida.',
            ];
        }

        $publicResponse = $this->fetchGithubRepoMetadata($repo);

        if ($publicResponse->ok()) {
            return [
                'status' => 'success',
                'repo' => $publicResponse->json(),
                'authenticated' => false,
            ];
        }

        $cuentaGithub = CuentaOauth::where('usuario_id', $usuarioId)
            ->where('provider', 'github')
            ->first();

        if (! $cuentaGithub || ! $cuentaGithub->access_token) {
            return [
                'status' => 'repo_not_found_or_no_access',
                'http_status' => 404,
                'message' => 'Sin acceso a repositorio o inexistente.',
            ];
        }

        $privateResponse = $this->fetchGithubRepoMetadata($repo, $cuentaGithub->access_token);

        if ($privateResponse->ok()) {
            return [
                'status' => 'success',
                'repo' => $privateResponse->json(),
                'authenticated' => true,
            ];
        }

        if (in_array($privateResponse->status(), [401, 403, 404], true)) {
            return [
                'status' => 'repo_not_found_or_no_access',
                'http_status' => 404,
                'message' => 'Sin acceso a repositorio o inexistente.',
            ];
        }

        return [
            'status' => 'github_error',
            'http_status' => 502,
            'message' => 'No se pudo validar el repositorio en GitHub.',
            'github_status' => $privateResponse->status(),
            'github_body' => $privateResponse->json(),
        ];
    }

    private function fetchGithubRepoMetadata(array $repo, ?string $accessToken = null): \Illuminate\Http\Client\Response
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'portafolio-app',
        ];

        if ($accessToken) {
            $headers['Authorization'] = "Bearer {$accessToken}";
        }

        return Http::timeout(20)
            ->withHeaders($headers)
            ->get("https://api.github.com/repos/{$repo['owner']}/{$repo['name']}");
    }

    private function saveGithubRepoForProject(int $idProyecto, string $url, array $repo): array
    {
        $githubRepoId = $repo['id'] ?? null;
        $proyectoRepo = ProyectoRepositorio::withTrashed()
            ->when($githubRepoId, function ($query) use ($githubRepoId) {
                $query->whereHas('github', fn ($githubQuery) => $githubQuery->where('github_repo_id', $githubRepoId));
            }, function ($query) use ($url) {
                $query->whereRaw('lower(url_repositorio) = ?', [strtolower($url)]);
            })
            ->where('proveedor', 'github')
            ->first();

        if ($proyectoRepo && ! is_null($proyectoRepo->id_proyecto) && (int) $proyectoRepo->id_proyecto !== $idProyecto) {
            return [
                'status' => 'linked_existing_project',
                'id_proyecto' => (int) $proyectoRepo->id_proyecto,
                'id_proyecto_repositorio' => (int) $proyectoRepo->id_proyecto_repositorio,
                'message' => 'Este repositorio ya esta vinculado a un proyecto existente.',
            ];
        }

        if (! $proyectoRepo) {
            $proyectoRepo = new ProyectoRepositorio();
        }

        if ($proyectoRepo->exists && $proyectoRepo->trashed()) {
            $proyectoRepo->restore();
        }

        $proyectoRepo->id_proyecto = $idProyecto;
        $proyectoRepo->nombre = $repo['name'] ?? $proyectoRepo->nombre;
        $proyectoRepo->tipo = $this->resolverTipoRepositorio($repo);
        $proyectoRepo->proveedor = 'github';
        $proyectoRepo->url_repositorio = $url;
        $proyectoRepo->descripcion = $repo['description'] ?? null;
        $proyectoRepo->save();

        $githubRepo = RepositorioGithub::firstOrNew([
            'id_proyecto_repositorio' => $proyectoRepo->id_proyecto_repositorio,
        ]);

        $githubRepo->github_repo_id = $repo['id'] ?? null;
        $githubRepo->github_owner = $repo['owner']['login'] ?? null;
        $githubRepo->github_repo_name = $repo['name'] ?? null;
        $githubRepo->github_description = $repo['description'] ?? null;
        $githubRepo->github_homepage = $repo['homepage'] ?? null;
        $githubRepo->default_branch = $repo['default_branch'] ?? null;
        $githubRepo->is_private = $this->dbBool($repo['private'] ?? false);
        $githubRepo->is_fork = $this->dbBool($repo['fork'] ?? false);
        $githubRepo->is_archived = $this->dbBool($repo['archived'] ?? false);
        $githubRepo->stars_count = (int) ($repo['stargazers_count'] ?? 0);
        $githubRepo->forks_count = (int) ($repo['forks_count'] ?? 0);
        $githubRepo->open_issues_count = (int) ($repo['open_issues_count'] ?? 0);
        $githubRepo->last_push_at = $repo['pushed_at'] ?? null;
        $githubRepo->repo_created_at = $repo['created_at'] ?? null;
        $githubRepo->repo_updated_at = $repo['updated_at'] ?? null;
        $githubRepo->sync_status = 'sincronizado';
        $githubRepo->sync_error = null;
        $githubRepo->last_sync_at = now();
        $githubRepo->save();

        return [
            'status' => 'success',
            'id_proyecto_repositorio' => $proyectoRepo->id_proyecto_repositorio,
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
                'message' => 'Debes enviar al menos un repositorio detectado para vincular.',
            ];
        }

        $participacion = DB::table('participaciones')
            ->where('id_usuario', $usuarioId)
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->first();

        if (false && ! $participacion) {
            return [
                'status' => 'participacion_not_found',
                'message' => 'No existe participación del usuario para el proyecto indicado.',
            ];
        }

        $repos = ProyectoRepositorio::query()
            ->with('github')
            ->whereIn('id_proyecto_repositorio', $ids->all())
            ->where('proveedor', 'github')
            ->get();

        if ($repos->count() !== $ids->count()) {
            return [
                'status' => 'repos_not_found',
                'message' => 'Uno o más repositorios detectados no existen o no son de GitHub.',
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

        if (! $participacion) {
            $participacion = $this->ensureParticipacionForProject($usuarioId, $idProyecto, $participacionData);

            if (! $participacion) {
                return [
                    'status' => 'participacion_not_found',
                    'message' => 'No existe participacion del usuario para el proyecto indicado.',
                ];
            }
        }

        $reposGithubIds = $repos
            ->map(fn ($repo) => $repo->github?->id_repositorio_github)
            ->filter()
            ->values();

        $validaciones = UsuarioRepositorioValidacion::query()
            ->where('id_usuario', $usuarioId)
            ->whereIn('id_repositorio_github', $reposGithubIds->all())
            ->get()
            ->keyBy('id_repositorio_github');

        DB::transaction(function () use ($repos, $validaciones, $participacion, $idProyecto, $participacionData) {
            // Update participation data if provided
            $participacionUpdate = array_filter([
                'rol'               => isset($participacionData['rol']) ? trim((string) $participacionData['rol']) : null,
                'descripcion_aporte' => isset($participacionData['descripcion_aporte']) ? trim((string) $participacionData['descripcion_aporte']) : null,
                'updated_at'        => now(),
            ], fn ($v) => ! is_null($v) && $v !== '');

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
                ->where('validado', $this->dbBool(true))
                ->exists();

            DB::table('participaciones')
                ->where('id_participacion', $participacion->id_participacion)
                ->update([
                    'participacion_validada' => $this->dbBool($hayValidado),
                    'updated_at' => now(),
                ]);
        });

        return [
            'status' => 'success',
            'message' => 'Repositorios vinculados al proyecto correctamente.',
            'id_proyecto' => $idProyecto,
            'id_participacion' => (int) $participacion->id_participacion,
            'repositorios_vinculados' => $repos->count(),
        ];
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
            ->whereNull('deleted_at')
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

    private function syncParticipacionRepositorios(int $usuarioId, object $participacion, $repos): void
    {
        $reposGithubIds = $repos
            ->map(fn ($repo) => $repo->github?->id_repositorio_github)
            ->filter()
            ->values();

        $validaciones = UsuarioRepositorioValidacion::query()
            ->where('id_usuario', $usuarioId)
            ->whereIn('id_repositorio_github', $reposGithubIds->all())
            ->get()
            ->keyBy('id_repositorio_github');

        foreach ($repos as $repo) {
            $validacion = $validaciones->get($repo->github?->id_repositorio_github);
            $isValid = (bool) ($validacion?->validado ?? false);

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
            ->where('validado', $this->dbBool(true))
            ->exists();

        DB::table('participaciones')
            ->where('id_participacion', $participacion->id_participacion)
            ->update([
                'participacion_validada' => $this->dbBool($hayValidado),
                'updated_at' => now(),
            ]);
    }

    public function syncForUsuario(int $usuarioId): array
    {
        $cuentaGithub = CuentaOauth::where('usuario_id', $usuarioId)
            ->where('provider', 'github')
            ->first();

        if (! $cuentaGithub) {
            return [
                'status' => 'not_linked',
                'message' => 'No hay una cuenta de GitHub vinculada para este usuario.',
            ];
        }

        if (! $cuentaGithub->access_token) {
            return [
                'status' => 'missing_token',
                'message' => 'La cuenta vinculada no tiene token de sincronización.',
            ];
        }

        $reposResponse = $this->fetchAllGithubRepos($cuentaGithub->access_token);

        if (($reposResponse['status'] ?? 'error') !== 'success') {
            return $reposResponse;
        }

        $repos = $reposResponse['repos'];
        $created = 0;
        $updated = 0;

        foreach ($repos as $repo) {
            $url = trim((string) ($repo['html_url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $githubRepoId = $repo['id'] ?? null;

            $proyectoRepo = ProyectoRepositorio::withTrashed()
                ->when($githubRepoId, function ($query) use ($githubRepoId) {
                    $query->whereHas('github', fn ($githubQuery) => $githubQuery->where('github_repo_id', $githubRepoId));
                }, function ($query) use ($url) {
                    $query->whereRaw('lower(url_repositorio) = ?', [strtolower($url)]);
                })
                ->where('proveedor', 'github')
                ->first();

            $isNew = false;

            if (! $proyectoRepo) {
                $proyectoRepo = new ProyectoRepositorio();
                $isNew = true;
            }

            if ($proyectoRepo->trashed()) {
                $proyectoRepo->restore();
                $proyectoRepo->id_proyecto = null;
            }

            $proyectoRepo->id_proyecto = $proyectoRepo->id_proyecto;
            $proyectoRepo->nombre = $repo['name'] ?? $proyectoRepo->nombre;
            $proyectoRepo->tipo = $this->resolverTipoRepositorio($repo);
            $proyectoRepo->proveedor = 'github';
            $proyectoRepo->url_repositorio = $url;
            $proyectoRepo->descripcion = $repo['description'] ?? null;
            $proyectoRepo->save();

            $githubRepo = RepositorioGithub::firstOrNew([
                'id_proyecto_repositorio' => $proyectoRepo->id_proyecto_repositorio,
            ]);

            $githubRepo->github_repo_id = $repo['id'] ?? null;
            $githubRepo->github_owner = $repo['owner']['login'] ?? null;
            $githubRepo->github_repo_name = $repo['name'] ?? null;
            $githubRepo->github_description = $repo['description'] ?? null;
            $githubRepo->github_homepage = $repo['homepage'] ?? null;
            $githubRepo->default_branch = $repo['default_branch'] ?? null;
            $githubRepo->is_private = $this->dbBool($repo['private'] ?? false);
            $githubRepo->is_fork = $this->dbBool($repo['fork'] ?? false);
            $githubRepo->is_archived = $this->dbBool($repo['archived'] ?? false);
            $githubRepo->stars_count = (int) ($repo['stargazers_count'] ?? 0);
            $githubRepo->forks_count = (int) ($repo['forks_count'] ?? 0);
            $githubRepo->open_issues_count = (int) ($repo['open_issues_count'] ?? 0);
            $githubRepo->last_push_at = $repo['pushed_at'] ?? null;
            $githubRepo->repo_created_at = $repo['created_at'] ?? null;
            $githubRepo->repo_updated_at = $repo['updated_at'] ?? null;
            $githubRepo->sync_status = 'sincronizado';
            $githubRepo->sync_error = null;
            $githubRepo->last_sync_at = now();
            $githubRepo->save();

            $esPropietario = (string) ($repo['owner']['id'] ?? '') !== ''
                && (string) ($repo['owner']['id'] ?? '') === (string) ($cuentaGithub->provider_user_id ?? '');

            $permisos = is_array($repo['permissions'] ?? null) ? $repo['permissions'] : [];
            $relacion = $this->resolverRelacionGithub($repo, $esPropietario);

            UsuarioRepositorioValidacion::updateOrCreate(
                [
                    'id_usuario' => $usuarioId,
                    'id_repositorio_github' => $githubRepo->id_repositorio_github,
                ],
                [
                    'id_cuenta_oauth' => $cuentaGithub->id_cuenta_oauth,
                    'relacion_github' => $relacion,
                    'es_propietario' => $this->dbBool($esPropietario),
                    'validado' => $this->dbBool(true),
                    'validado_at' => now(),
                    'ultima_verificacion_at' => now(),
                    'permisos_github' => $permisos,
                    'detalle_validacion' => 'Validado por sincronización automática desde GitHub.',
                ]
            );

            if ($isNew) {
                $created++;
            } else {
                $updated++;
            }
        }

        $cuentaGithub->token_updated_at = now();
        $cuentaGithub->save();

        return [
            'status' => 'success',
            'message' => 'Repositorios sincronizados correctamente.',
            'stats' => [
                'total_remotos' => count($repos),
                'creados' => $created,
                'actualizados' => $updated,
            ],
        ];
    }

    private function fetchAllGithubRepos(string $accessToken): array
    {
        $repos = [];
        $page = 1;

        do {
            $response = Http::timeout(20)
                ->withHeaders([
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent' => 'portafolio-app',
                ])
                ->get('https://api.github.com/user/repos', [
                    'affiliation' => 'owner,collaborator,organization_member',
                    'sort' => 'updated',
                    'direction' => 'desc',
                    'per_page' => 100,
                    'page' => $page,
                ]);

            if ($response->status() === 401) {
                return [
                    'status' => 'invalid_token',
                    'message' => 'Token GitHub inválido o expirado, vuelve a vincular la cuenta.',
                ];
            }

            if (! $response->ok()) {
                return [
                    'status' => 'github_error',
                    'message' => 'No se pudo consultar repositorios en GitHub.',
                    'github_status' => $response->status(),
                    'github_body' => $response->json(),
                ];
            }

            $chunk = $response->json();
            if (! is_array($chunk)) {
                $chunk = [];
            }

            $repos = array_merge($repos, $chunk);
            $page++;
        } while (count($chunk) === 100);

        return [
            'status' => 'success',
            'repos' => $repos,
        ];
    }

    private function resolverRelacionGithub(array $repo, bool $esPropietario): string
    {
        if ($esPropietario) {
            return 'owner';
        }

        $permissions = $repo['permissions'] ?? [];

        if (! empty($permissions['admin'])) {
            return 'maintainer';
        }

        if (! empty($permissions['push']) || ! empty($permissions['pull'])) {
            return 'collaborator';
        }

        if (! empty($repo['permissions'])) {
            return 'member';
        }

        return 'unknown';
    }

    private function dbBool(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }

    private function parseGithubRepoUrl(string $repoUrl): ?array
    {
        $raw = trim($repoUrl);
        if ($raw === '') {
            return null;
        }

        $parts = parse_url($raw);
        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($host, ['github.com', 'www.github.com'], true)) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), fn ($segment) => $segment !== ''));
        if (count($segments) < 2) {
            return null;
        }

        $owner = rawurldecode($segments[0]);
        $repo = rawurldecode($segments[1]);
        $repo = preg_replace('/\.git$/i', '', $repo) ?? $repo;

        if ($owner === '' || $repo === '') {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $owner) || ! preg_match('/^[A-Za-z0-9_.-]+$/', $repo)) {
            return null;
        }

        return [
            'owner' => $owner,
            'name' => $repo,
        ];
    }

    private function resolverTipoRepositorio(array $repo): string
    {
        $name = strtolower((string) ($repo['name'] ?? ''));
        $description = strtolower((string) ($repo['description'] ?? ''));

        if (str_contains($name, 'front') || str_contains($description, 'frontend')) {
            return 'frontend';
        }

        if (str_contains($name, 'back') || str_contains($description, 'backend') || str_contains($description, 'api')) {
            return 'backend';
        }

        if (! empty($repo['language']) && strtolower((string) $repo['language']) === 'dart') {
            return 'mobile';
        }

        return 'otro';
    }
}
