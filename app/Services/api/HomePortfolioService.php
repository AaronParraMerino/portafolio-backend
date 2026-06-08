<?php

namespace App\Services\api;

use App\Models\HabilidadUsuario;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HomePortfolioService
{
    private const DEFAULT_LIMIT = 6;
    private const RECENT_PROJECTS_LIMIT = 12;
    private const HERO_PROJECTS_LIMIT = 6;
    private const SKILLS_LIMIT = 3;
    private const EXPERIENCES_LIMIT = 3;

    public function __construct(private readonly ProfileImageVariantService $profileImageVariants)
    {
    }

    public function getFeaturedPortfolios(int $limit = self::DEFAULT_LIMIT, ?string $search = null): array
    {
        $limit = max(1, min($limit, 12));
        $topProjects = $this->topProjects($limit);

        return [
            'ultimas_actualizaciones' => $this->recentlyUpdated($limit),
            'mas_proyectos' => $topProjects,
            'mas_experiencia' => $this->rankedBy('total_experiencias', $limit),
            'mas_habilidades' => $this->rankedBy('total_habilidades', $limit),
            'meta' => [
                'proyectos_disponibles' => count($topProjects) > 0,
                'mensaje_proyectos' => count($topProjects) > 0
                    ? null
                    : 'Este bloque se activara cuando existan proyectos publicos disponibles.',
            ],
        ];
    }

    public function getStats(): array
    {
        return [
            'developers' => $this->publicDevelopersCount(),
            'projects' => $this->publicProjectsCount(),
            'technologies' => $this->publicTechnologiesCount(),
            'events' => $this->publicEventsCount(),
        ];
    }

    public function getRecentProjects(int $limit = self::RECENT_PROJECTS_LIMIT): array
    {
        $limit = max(self::HERO_PROJECTS_LIMIT, min($limit, 24));

        $projects = DB::table('proyectos as p')
            ->where('p.estado_publicacion', 'publicado')
            ->whereNull('p.deleted_at')
            ->whereExists(function ($query) {
                $query
                    ->selectRaw('1')
                    ->from('participaciones as par')
                    ->whereColumn('par.id_proyecto', 'p.id_proyecto')
                    ->where('par.visibilidad', 'publico')
                    ->whereNull('par.deleted_at');
            })
            ->orderByRaw('COALESCE(p.publicado_at, p.updated_at, p.created_at) DESC')
            ->orderByDesc('p.id_proyecto')
            ->limit($limit)
            ->get([
                'p.id_proyecto',
                'p.titulo',
                'p.descripcion',
                'p.categoria_proyecto',
                'p.plataforma_objetivo',
                'p.estado_desarrollo',
                'p.fecha_inicio',
                'p.fecha_fin',
                'p.es_destacado',
                'p.publicado_at',
                'p.created_at',
                'p.updated_at',
            ]);

        $projectIds = $projects
            ->pluck('id_proyecto')
            ->map(fn ($id) => (int) $id)
            ->all();

        $technologies = $this->recentProjectTechnologies($projectIds);
        $coverImages = $this->recentProjectCoverImages($projectIds);

        $items = $projects
            ->map(function ($project) use ($technologies, $coverImages) {
                $id = (int) $project->id_proyecto;
                $projectTechnologies = collect($technologies[$id] ?? []);
                $cover = $coverImages[$id] ?? null;

                return [
                    'id' => $id,
                    'id_proyecto' => $id,
                    'titulo' => $project->titulo,
                    'descripcion' => $project->descripcion,
                    'tipo' => $project->categoria_proyecto,
                    'categoria_proyecto' => $project->categoria_proyecto,
                    'desarrollado_para' => $project->plataforma_objetivo,
                    'plataforma_objetivo' => $project->plataforma_objetivo,
                    'estado_desarrollo' => $project->estado_desarrollo,
                    'fecha_inicio' => $project->fecha_inicio,
                    'fecha_fin' => $project->fecha_fin,
                    'es_destacado' => (bool) $project->es_destacado,
                    'publicado_at' => $project->publicado_at,
                    'updated_at' => $project->updated_at,
                    'imagen_portada' => $cover,
                    'tecnologias' => $projectTechnologies->pluck('nombre')->values()->all(),
                    'tecnologias_detalle' => $projectTechnologies->values()->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'hero' => array_slice($items, 0, self::HERO_PROJECTS_LIMIT),
            'recientes' => $items,
            'meta' => [
                'hero_limit' => self::HERO_PROJECTS_LIMIT,
                'recent_limit' => $limit,
                'total' => count($items),
            ],
        ];
    }

    public function getPublicProjectDetail(int $projectId): ?array
    {
        $project = DB::table('proyectos as p')
            ->where('p.id_proyecto', $projectId)
            ->where('p.estado_publicacion', 'publicado')
            ->whereNull('p.deleted_at')
            ->whereExists(function ($query) {
                $query
                    ->selectRaw('1')
                    ->from('participaciones as par')
                    ->whereColumn('par.id_proyecto', 'p.id_proyecto')
                    ->where('par.visibilidad', 'publico')
                    ->whereNull('par.deleted_at');
            })
            ->first();

        if (! $project) {
            return null;
        }

        $evidences = DB::table('proyecto_evidencias')
            ->where('id_proyecto', $projectId)
            ->whereRaw('es_visible IS TRUE')
            ->whereNull('deleted_at')
            ->orderByDesc('es_portada')
            ->orderBy('orden')
            ->orderBy('id_evidencia')
            ->get()
            ->map(function ($evidence) {
                $item = (array) $evidence;

                if (in_array(strtolower((string) ($item['tipo'] ?? '')), ['imagen', 'captura'], true)) {
                    $variants = $this->profileImageVariants->getProjectVariantUrls($item['url'] ?? null);
                    $item['imagen_card_url'] = $variants['card'] ?? null;
                    $item['imagen_detail_url'] = $variants['detail'] ?? null;
                }

                return $item;
            })
            ->values()
            ->all();

        $technologies = DB::table('uso_tecnologias as ut')
            ->join('tecnologias as t', 't.id_tecnologia', '=', 'ut.id_tecnologia')
            ->where('ut.id_proyecto', $projectId)
            ->whereRaw('ut.es_visible IS TRUE')
            ->whereNull('ut.deleted_at')
            ->whereNull('t.deleted_at')
            ->orderByDesc('ut.es_principal')
            ->orderBy('t.nombre')
            ->get([
                't.id_tecnologia',
                't.nombre',
                't.tipo',
                't.icono_url',
                't.color',
                't.descripcion',
                'ut.version_usada',
                'ut.porcentaje_uso',
                'ut.es_principal',
            ])
            ->map(fn ($technology) => (array) $technology)
            ->values()
            ->all();

        $repositories = DB::table('proyecto_repositorios as pr')
            ->leftJoin('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->where('pr.id_proyecto', $projectId)
            ->whereNull('pr.deleted_at')
            ->orderBy('pr.id_proyecto_repositorio')
            ->get([
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
                'rg.default_branch',
                'rg.is_fork',
                'rg.is_archived',
                'rg.stars_count',
                'rg.forks_count',
                'rg.open_issues_count',
                'rg.commits_count',
                'rg.contributors_count',
                'rg.last_commit_message',
                'rg.last_commit_date',
                'rg.last_push_at',
                'rg.repo_created_at',
                'rg.repo_updated_at',
                'rg.readme_resumen',
                'rg.last_sync_at',
            ])
            ->map(fn ($repository) => (array) $repository)
            ->values()
            ->all();

        $repositoryLinks = DB::table('participacion_repositorios as participant_repository')
            ->join('proyecto_repositorios as project_repository', 'project_repository.id_proyecto_repositorio', '=', 'participant_repository.id_proyecto_repositorio')
            ->where('project_repository.id_proyecto', $projectId)
            ->get([
                'participant_repository.id_participacion',
                'participant_repository.validado',
                'participant_repository.es_propietario',
            ])
            ->groupBy('id_participacion');

        $participants = DB::table('participaciones as par')
            ->join('usuarios as u', 'u.id_usuario', '=', 'par.id_usuario')
            ->leftJoin('perfiles as pe', 'pe.usuario_id', '=', 'u.id_usuario')
            ->leftJoin('cuentas_oauth as co', function ($join) {
                $join->on('co.usuario_id', '=', 'u.id_usuario')
                    ->where('co.provider', '=', 'github');
            })
            ->where('par.id_proyecto', $projectId)
            ->where('par.visibilidad', 'publico')
            ->whereNull('par.deleted_at')
            ->whereIn('u.estado', ['activo', 'pausado'])
            ->orderByDesc('par.es_propietario')
            ->orderBy('u.nombre')
            ->get([
                'par.id_participacion',
                'par.id_usuario',
                'par.rol',
                'par.descripcion_aporte',
                'par.es_propietario',
                'par.participacion_validada',
                'par.estado_participacion',
                'par.fecha_inicio',
                'par.fecha_fin',
                'u.nombre',
                'u.apellido',
                'pe.foto_perfil',
                'co.nombre as github_username',
                'co.foto_url as github_avatar_url',
            ])
            ->map(function ($participant) use ($repositoryLinks) {
                $avatar = $participant->foto_perfil ?: $participant->github_avatar_url;
                $participantRepositoryLinks = $repositoryLinks->get($participant->id_participacion, collect());

                return [
                    'id_participacion' => (int) $participant->id_participacion,
                    'id_usuario' => (int) $participant->id_usuario,
                    'nombre' => trim(($participant->nombre ?? '') . ' ' . ($participant->apellido ?? '')),
                    'rol' => $participant->rol,
                    'descripcion_aporte' => $participant->descripcion_aporte,
                    'es_propietario' => (bool) $participant->es_propietario,
                    'participacion_validada' => (bool) $participant->participacion_validada,
                    'estado_participacion' => $participant->estado_participacion,
                    'fecha_inicio' => $participant->fecha_inicio,
                    'fecha_fin' => $participant->fecha_fin,
                    'avatar_url' => $avatar,
                    'avatar_thumb_url' => $this->profileImageVariants->getVariantUrl($participant->foto_perfil, 'thumb'),
                    'github_username' => $participant->github_username,
                    'vinculado_repositorio' => $participantRepositoryLinks->contains(
                        fn ($link) => (bool) $link->validado || (bool) $link->es_propietario
                    ),
                    'es_propietario_repositorio' => $participantRepositoryLinks->contains(
                        fn ($link) => (bool) $link->es_propietario
                    ),
                    'ruta_portafolio' => '/portafolio/' . $participant->id_usuario,
                ];
            })
            ->values()
            ->all();

        return [
            ...(array) $project,
            'id' => $projectId,
            'tipo' => $project->categoria_proyecto,
            'desarrollado_para' => $project->plataforma_objetivo,
            'evidencias' => $evidences,
            'tecnologias' => $technologies,
            'repositorios' => $repositories,
            'participantes' => $participants,
            'participantes_count' => count($participants),
        ];
    }

    public function getPublicDevelopers(int $page = 1, int $perPage = 20, ?string $search = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 50));
        $query = $this->basePortfolioQuery();
        $term = trim((string) $search);

        if ($term !== '') {
            $like = '%' . $term . '%';
            $query->whereRaw("LOWER(usuarios.nombre || ' ' || usuarios.apellido) LIKE LOWER(?)", [$like]);
        }

        $paginator = $query
            ->orderByDesc('ultima_actividad')
            ->orderBy('usuarios.nombre')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => $this->mapPortfolios(new Collection($paginator->items())),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function topProjects(int $limit): array
    {
        return $this->rankedBy('total_proyectos', $limit);
    }

    private function recentProjectTechnologies(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        return DB::table('uso_tecnologias as ut')
            ->join('tecnologias as t', 't.id_tecnologia', '=', 'ut.id_tecnologia')
            ->whereIn('ut.id_proyecto', $projectIds)
            ->whereRaw('ut.es_visible IS TRUE')
            ->whereNull('ut.deleted_at')
            ->whereNull('t.deleted_at')
            ->orderByDesc('ut.es_principal')
            ->orderBy('t.nombre')
            ->get([
                'ut.id_proyecto',
                't.id_tecnologia',
                't.nombre',
                't.tipo',
                't.icono_url',
                't.color',
            ])
            ->groupBy('id_proyecto')
            ->map(fn ($items) => $items
                ->map(fn ($technology) => (array) $technology)
                ->values()
                ->all())
            ->all();
    }

    private function recentProjectCoverImages(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        return DB::table('proyecto_evidencias')
            ->whereIn('id_proyecto', $projectIds)
            ->whereIn('tipo', ['imagen', 'captura'])
            ->whereRaw('es_visible IS TRUE')
            ->whereNull('deleted_at')
            ->orderByDesc('es_portada')
            ->orderBy('orden')
            ->orderBy('id_evidencia')
            ->get([
                'id_evidencia',
                'id_proyecto',
                'titulo',
                'tipo',
                'url',
                'es_portada',
                'orden',
            ])
            ->groupBy('id_proyecto')
            ->map(function ($items) {
                $cover = $items->first();
                $variants = $this->profileImageVariants->getProjectVariantUrls($cover->url ?? null);

                return [
                    'id_evidencia' => (int) $cover->id_evidencia,
                    'titulo' => $cover->titulo,
                    'tipo' => $cover->tipo,
                    'url' => $cover->url,
                    'imagen_card_url' => $variants['card'] ?? null,
                    'imagen_detail_url' => $variants['detail'] ?? null,
                ];
            })
            ->all();
    }

    private function publicDevelopersCount(): int
    {
        return (int) DB::table('usuarios')
            ->join('perfiles', 'perfiles.usuario_id', '=', 'usuarios.id_usuario')
            ->whereIn('usuarios.estado', ['activo', 'pausado'])
            ->where('usuarios.rol', '!=', 'admin')
            ->whereRaw('perfiles.es_publico = true')
            ->distinct('usuarios.id_usuario')
            ->count('usuarios.id_usuario');
    }

    private function publicProjectsCount(): int
    {
        return (int) DB::table('proyectos')
            ->join('participaciones', 'participaciones.id_proyecto', '=', 'proyectos.id_proyecto')
            ->where('participaciones.visibilidad', 'publico')
            ->whereNull('participaciones.deleted_at')
            ->whereNull('proyectos.deleted_at')
            ->distinct('proyectos.id_proyecto')
            ->count('proyectos.id_proyecto');
    }

    private function publicTechnologiesCount(): int
    {
        return (int) DB::table('tecnologias')
            ->join('uso_tecnologias', 'uso_tecnologias.id_tecnologia', '=', 'tecnologias.id_tecnologia')
            ->join('proyectos', 'proyectos.id_proyecto', '=', 'uso_tecnologias.id_proyecto')
            ->join('participaciones', 'participaciones.id_proyecto', '=', 'proyectos.id_proyecto')
            ->where('participaciones.visibilidad', 'publico')
            ->whereNull('participaciones.deleted_at')
            ->whereNull('uso_tecnologias.deleted_at')
            ->whereNull('proyectos.deleted_at')
            ->whereNull('tecnologias.deleted_at')
            ->distinct('tecnologias.id_tecnologia')
            ->count('tecnologias.id_tecnologia');
    }

    private function publicEventsCount(): int
    {
        return (int) DB::table('admin_eventos')
            ->whereIn('estado', ['activo', 'programado'])
            ->where(function ($query) {
                $query->whereNull('fecha_fin')
                    ->orWhere('fecha_fin', '>=', now());
            })
            ->count();
    }

    public function getPublicPortfolio(int $userId): ?array
    {
        $user = $this->basePortfolioQuery()
            ->where('usuarios.id_usuario', $userId)
            ->first();

        if (! $user) {
            return null;
        }

        return $this->mapPortfolios(new Collection([$user]))[0] ?? null;
    }

    private function recentlyUpdated(int $limit): array
    {
        $users = $this->basePortfolioQuery()
            ->orderByDesc('ultima_actividad')
            ->orderBy('usuarios.nombre')
            ->limit($limit)
            ->get();

        return $this->mapPortfolios($users);
    }

    private function rankedBy(string $field, int $limit): array
    {
        $query = $this->basePortfolioQuery();

        if ($field === 'total_proyectos') {
            $query->whereRaw('COALESCE(proyectos_publicos.total_proyectos, 0) > 0');
        }

        if ($field === 'total_experiencias') {
            $query->whereRaw('COALESCE(experiencias_publicas.total_experiencias, 0) > 0');
        }

        if ($field === 'total_habilidades') {
            $query->whereRaw('COALESCE(habilidades_publicas.total_habilidades, 0) > 0');
        }

        if ($field === 'total_proyectos') {
            $query->whereRaw('COALESCE(proyectos_publicos.total_proyectos, 0) > 0');
        }

        $users = $query
            ->orderByDesc($field)
            ->orderByDesc('ultima_actividad')
            ->orderBy('usuarios.nombre')
            ->limit($limit)
            ->get();

        return $this->mapPortfolios($users);
    }

    private function searchRanked(string $term, int $limit): array
    {
        $normalized = $this->normalizeSearchTerm($term);
        $like = '%' . $term . '%';
        $query = $this->basePortfolioQuery();

        $this->applySearchFilter($query, $like, $normalized);
        $this->addSearchScore($query, $like, $normalized);

        $users = $query
            ->orderByDesc('score_coincidencia')
            ->orderByDesc('score_total')
            ->orderByDesc('total_proyectos')
            ->orderByDesc('total_experiencias')
            ->orderByDesc('total_habilidades')
            ->orderByDesc('ultima_actividad')
            ->orderBy('usuarios.nombre')
            ->limit($limit)
            ->get();

        return $this->mapPortfolios($users);
    }

    private function basePortfolioQuery()
    {
        $experienceTotals = DB::table('experiencias')
            ->select('usuario_id')
            ->selectRaw('COUNT(*) AS total_experiencias')
            ->selectRaw('MAX(fecha_modificacion) AS experiencias_actualizadas')
            ->whereRaw('es_publico = true')
            ->groupBy('usuario_id');

        $skillTotals = DB::table('habilidades_usuario')
            ->join('habilidades', 'habilidades.id_habilidad', '=', 'habilidades_usuario.habilidad_id')
            ->select('habilidades_usuario.usuario_id')
            ->selectRaw('COUNT(*) AS total_habilidades')
            ->selectRaw('MAX(COALESCE(habilidades_usuario.fecha_modificacion, habilidades_usuario.updated_at, habilidades_usuario.created_at)) AS habilidades_actualizadas')
            ->whereRaw('habilidades_usuario.es_visible = true')
            ->whereRaw('habilidades.estado = true')
            ->groupBy('habilidades_usuario.usuario_id');

        $projectTotals = DB::table('participaciones')
            ->join('proyectos', 'proyectos.id_proyecto', '=', 'participaciones.id_proyecto')
            ->select('participaciones.id_usuario')
            ->selectRaw('COUNT(DISTINCT proyectos.id_proyecto) AS total_proyectos')
            ->selectRaw('MAX(COALESCE(proyectos.updated_at, participaciones.updated_at)) AS proyectos_actualizados')
            ->where('participaciones.visibilidad', 'publico')
            ->whereNull('participaciones.deleted_at')
            ->whereNull('proyectos.deleted_at')
            ->groupBy('participaciones.id_usuario');

        return Usuario::query()
            ->join('perfiles', 'perfiles.usuario_id', '=', 'usuarios.id_usuario')
            ->leftJoin('visibilidad_campos as vis_profesion', function ($join) {
                $join->on('vis_profesion.usuario_id', '=', 'usuarios.id_usuario')
                    ->whereRaw("vis_profesion.campo = 'profesion'");
            })
            ->leftJoin('visibilidad_campos as vis_biografia', function ($join) {
                $join->on('vis_biografia.usuario_id', '=', 'usuarios.id_usuario')
                    ->whereRaw("vis_biografia.campo = 'biografia'");
            })
            ->leftJoin('visibilidad_campos as vis_ciudad', function ($join) {
                $join->on('vis_ciudad.usuario_id', '=', 'usuarios.id_usuario')
                    ->whereRaw("vis_ciudad.campo = 'ciudad'");
            })
            ->leftJoin('visibilidad_campos as vis_pais', function ($join) {
                $join->on('vis_pais.usuario_id', '=', 'usuarios.id_usuario')
                    ->whereRaw("vis_pais.campo = 'pais'");
            })
            ->leftJoinSub($experienceTotals, 'experiencias_publicas', function ($join) {
                $join->on('experiencias_publicas.usuario_id', '=', 'usuarios.id_usuario');
            })
            ->leftJoinSub($skillTotals, 'habilidades_publicas', function ($join) {
                $join->on('habilidades_publicas.usuario_id', '=', 'usuarios.id_usuario');
            })
            ->leftJoinSub($projectTotals, 'proyectos_publicos', function ($join) {
                $join->on('proyectos_publicos.id_usuario', '=', 'usuarios.id_usuario');
            })
            ->whereIn('usuarios.estado', ['activo', 'pausado'])
            ->where('usuarios.rol', '!=', 'admin')
            ->whereRaw('perfiles.es_publico = true')
            ->select([
                'usuarios.id_usuario',
                'usuarios.nombre',
                'usuarios.apellido',
                'perfiles.foto_perfil',
                'usuarios.updated_at as usuario_actualizado',
                'perfiles.updated_at as perfil_actualizado',
            ])
            ->selectRaw('CASE WHEN COALESCE(vis_profesion.visible, false) = true THEN perfiles.profesion ELSE NULL END AS profesion')
            ->selectRaw('CASE WHEN COALESCE(vis_biografia.visible, false) = true THEN perfiles.biografia ELSE NULL END AS biografia')
            ->selectRaw('CASE WHEN COALESCE(vis_ciudad.visible, false) = true THEN perfiles.ciudad ELSE NULL END AS ciudad')
            ->selectRaw('CASE WHEN COALESCE(vis_pais.visible, false) = true THEN perfiles.pais ELSE NULL END AS pais')
            ->selectRaw('COALESCE(experiencias_publicas.total_experiencias, 0) AS total_experiencias')
            ->selectRaw('COALESCE(habilidades_publicas.total_habilidades, 0) AS total_habilidades')
            ->selectRaw('COALESCE(proyectos_publicos.total_proyectos, 0) AS total_proyectos')
            ->selectRaw("
                GREATEST(
                    COALESCE(usuarios.updated_at, '1970-01-01'),
                    COALESCE(perfiles.updated_at, '1970-01-01'),
                    COALESCE(experiencias_publicas.experiencias_actualizadas, '1970-01-01'),
                    COALESCE(habilidades_publicas.habilidades_actualizadas, '1970-01-01'),
                    COALESCE(proyectos_publicos.proyectos_actualizados, '1970-01-01')
                ) AS ultima_actividad
            ");
    }

    private function applySearchFilter($query, string $like, string $normalized): void
    {
        $query->where(function ($where) use ($like, $normalized) {
            $where
                ->whereExists(fn ($sub) => $this->skillMatchQuery($sub, $normalized))
                ->orWhereExists(fn ($sub) => $this->projectMatchQuery($sub, $like))
                ->orWhereExists(fn ($sub) => $this->experienceMatchQuery($sub, $like))
                ->orWhere(function ($profile) use ($like) {
                    $profile
                        ->where(function ($field) use ($like) {
                            $field->whereRaw('COALESCE(vis_profesion.visible, false) = true')
                                ->where('perfiles.profesion', 'ilike', $like);
                        })
                        ->orWhere(function ($field) use ($like) {
                            $field->whereRaw('COALESCE(vis_biografia.visible, false) = true')
                                ->where('perfiles.biografia', 'ilike', $like);
                        })
                        ->orWhereRaw("LOWER(usuarios.nombre || ' ' || usuarios.apellido) LIKE LOWER(?)", [$like]);
                });
        });
    }

    private function addSearchScore($query, string $like, string $normalized): void
    {
        $query
            ->selectRaw('CASE WHEN EXISTS (
                SELECT 1
                FROM habilidades_usuario hu_score
                INNER JOIN habilidades h_score ON h_score.id_habilidad = hu_score.habilidad_id
                WHERE hu_score.usuario_id = usuarios.id_usuario
                    AND hu_score.es_visible = true
                    AND h_score.estado = true
                    AND LOWER(h_score.nombre_normalizado) = ?
            ) THEN 50 ELSE 0 END AS score_habilidad', [$normalized])
            ->selectRaw('CASE WHEN EXISTS (
                SELECT 1
                FROM participaciones par_score
                INNER JOIN proyectos p_score ON p_score.id_proyecto = par_score.id_proyecto
                WHERE par_score.id_usuario = usuarios.id_usuario
                    AND par_score.visibilidad = ?
                    AND par_score.deleted_at IS NULL
                    AND p_score.deleted_at IS NULL
                    AND (
                        p_score.titulo ILIKE ?
                        OR p_score.descripcion ILIKE ?
                        OR p_score.categoria_proyecto ILIKE ?
                        OR EXISTS (
                            SELECT 1
                            FROM uso_tecnologias ut_score
                            INNER JOIN tecnologias t_score ON t_score.id_tecnologia = ut_score.id_tecnologia
                            WHERE ut_score.id_proyecto = p_score.id_proyecto
                                AND ut_score.deleted_at IS NULL
                                AND t_score.deleted_at IS NULL
                                AND t_score.nombre ILIKE ?
                        )
                    )
            ) THEN 30 ELSE 0 END AS score_proyecto', ['publico', $like, $like, $like, $like])
            ->selectRaw('CASE WHEN EXISTS (
                SELECT 1
                FROM experiencias ex_score
                WHERE ex_score.usuario_id = usuarios.id_usuario
                    AND ex_score.es_publico = true
                    AND (
                        ex_score.cargo ILIKE ?
                        OR ex_score.institucion ILIKE ?
                        OR ex_score.descripcion ILIKE ?
                        OR ex_score.tipo ILIKE ?
                    )
            ) THEN 20 ELSE 0 END AS score_experiencia', [$like, $like, $like, $like])
            ->selectRaw("CASE WHEN (
                (COALESCE(vis_profesion.visible, false) = true AND perfiles.profesion ILIKE ?)
                OR (COALESCE(vis_biografia.visible, false) = true AND perfiles.biografia ILIKE ?)
                OR LOWER(usuarios.nombre || ' ' || usuarios.apellido) LIKE LOWER(?)
            ) THEN 10 ELSE 0 END AS score_perfil", [$like, $like, $like])
            ->selectRaw("
                (
                    CASE WHEN EXISTS (
                        SELECT 1
                        FROM habilidades_usuario hu_score
                        INNER JOIN habilidades h_score ON h_score.id_habilidad = hu_score.habilidad_id
                        WHERE hu_score.usuario_id = usuarios.id_usuario
                            AND hu_score.es_visible = true
                            AND h_score.estado = true
                            AND LOWER(h_score.nombre_normalizado) = ?
                    ) THEN 50 ELSE 0 END
                    +
                    CASE WHEN EXISTS (
                        SELECT 1
                        FROM participaciones par_score
                        INNER JOIN proyectos p_score ON p_score.id_proyecto = par_score.id_proyecto
                        WHERE par_score.id_usuario = usuarios.id_usuario
                            AND par_score.visibilidad = ?
                            AND par_score.deleted_at IS NULL
                            AND p_score.deleted_at IS NULL
                            AND (
                                p_score.titulo ILIKE ?
                                OR p_score.descripcion ILIKE ?
                                OR p_score.categoria_proyecto ILIKE ?
                                OR EXISTS (
                                    SELECT 1
                                    FROM uso_tecnologias ut_score
                                    INNER JOIN tecnologias t_score ON t_score.id_tecnologia = ut_score.id_tecnologia
                                    WHERE ut_score.id_proyecto = p_score.id_proyecto
                                        AND ut_score.deleted_at IS NULL
                                        AND t_score.deleted_at IS NULL
                                        AND t_score.nombre ILIKE ?
                                )
                            )
                    ) THEN 30 ELSE 0 END
                    +
                    CASE WHEN EXISTS (
                        SELECT 1
                        FROM experiencias ex_score
                        WHERE ex_score.usuario_id = usuarios.id_usuario
                            AND ex_score.es_publico = true
                            AND (
                                ex_score.cargo ILIKE ?
                                OR ex_score.institucion ILIKE ?
                                OR ex_score.descripcion ILIKE ?
                                OR ex_score.tipo ILIKE ?
                            )
                    ) THEN 20 ELSE 0 END
                    +
                    CASE WHEN (
                        (COALESCE(vis_profesion.visible, false) = true AND perfiles.profesion ILIKE ?)
                        OR (COALESCE(vis_biografia.visible, false) = true AND perfiles.biografia ILIKE ?)
                        OR LOWER(usuarios.nombre || ' ' || usuarios.apellido) LIKE LOWER(?)
                    ) THEN 10 ELSE 0 END
                    +
                    LEAST(COALESCE(proyectos_publicos.total_proyectos, 0) * 2, 20)
                    +
                    LEAST(COALESCE(experiencias_publicas.total_experiencias, 0) * 2, 20)
                    +
                    LEAST(COALESCE(habilidades_publicas.total_habilidades, 0), 15)
                    +
                    CASE
                        WHEN GREATEST(
                            COALESCE(usuarios.updated_at, '1970-01-01'),
                            COALESCE(perfiles.updated_at, '1970-01-01'),
                            COALESCE(experiencias_publicas.experiencias_actualizadas, '1970-01-01'),
                            COALESCE(habilidades_publicas.habilidades_actualizadas, '1970-01-01'),
                            COALESCE(proyectos_publicos.proyectos_actualizados, '1970-01-01')
                        ) >= NOW() - INTERVAL '7 days' THEN 15
                        WHEN GREATEST(
                            COALESCE(usuarios.updated_at, '1970-01-01'),
                            COALESCE(perfiles.updated_at, '1970-01-01'),
                            COALESCE(experiencias_publicas.experiencias_actualizadas, '1970-01-01'),
                            COALESCE(habilidades_publicas.habilidades_actualizadas, '1970-01-01'),
                            COALESCE(proyectos_publicos.proyectos_actualizados, '1970-01-01')
                        ) >= NOW() - INTERVAL '30 days' THEN 10
                        WHEN GREATEST(
                            COALESCE(usuarios.updated_at, '1970-01-01'),
                            COALESCE(perfiles.updated_at, '1970-01-01'),
                            COALESCE(experiencias_publicas.experiencias_actualizadas, '1970-01-01'),
                            COALESCE(habilidades_publicas.habilidades_actualizadas, '1970-01-01'),
                            COALESCE(proyectos_publicos.proyectos_actualizados, '1970-01-01')
                        ) >= NOW() - INTERVAL '90 days' THEN 5
                        ELSE 0
                    END
                ) AS score_total
            ", [
                $normalized,
                'publico', $like, $like, $like, $like,
                $like, $like, $like, $like,
                $like, $like, $like,
            ])
            ->selectRaw("
                (
                    CASE WHEN EXISTS (
                        SELECT 1
                        FROM habilidades_usuario hu_score
                        INNER JOIN habilidades h_score ON h_score.id_habilidad = hu_score.habilidad_id
                        WHERE hu_score.usuario_id = usuarios.id_usuario
                            AND hu_score.es_visible = true
                            AND h_score.estado = true
                            AND LOWER(h_score.nombre_normalizado) = ?
                    ) THEN 50 ELSE 0 END
                    +
                    CASE WHEN EXISTS (
                        SELECT 1
                        FROM participaciones par_score
                        INNER JOIN proyectos p_score ON p_score.id_proyecto = par_score.id_proyecto
                        WHERE par_score.id_usuario = usuarios.id_usuario
                            AND par_score.visibilidad = ?
                            AND par_score.deleted_at IS NULL
                            AND p_score.deleted_at IS NULL
                            AND (
                                p_score.titulo ILIKE ?
                                OR p_score.descripcion ILIKE ?
                                OR p_score.categoria_proyecto ILIKE ?
                                OR EXISTS (
                                    SELECT 1
                                    FROM uso_tecnologias ut_score
                                    INNER JOIN tecnologias t_score ON t_score.id_tecnologia = ut_score.id_tecnologia
                                    WHERE ut_score.id_proyecto = p_score.id_proyecto
                                        AND ut_score.deleted_at IS NULL
                                        AND t_score.deleted_at IS NULL
                                        AND t_score.nombre ILIKE ?
                                )
                            )
                    ) THEN 30 ELSE 0 END
                    +
                    CASE WHEN EXISTS (
                        SELECT 1
                        FROM experiencias ex_score
                        WHERE ex_score.usuario_id = usuarios.id_usuario
                            AND ex_score.es_publico = true
                            AND (
                                ex_score.cargo ILIKE ?
                                OR ex_score.institucion ILIKE ?
                                OR ex_score.descripcion ILIKE ?
                                OR ex_score.tipo ILIKE ?
                            )
                    ) THEN 20 ELSE 0 END
                    +
                    CASE WHEN (
                        (COALESCE(vis_profesion.visible, false) = true AND perfiles.profesion ILIKE ?)
                        OR (COALESCE(vis_biografia.visible, false) = true AND perfiles.biografia ILIKE ?)
                        OR LOWER(usuarios.nombre || ' ' || usuarios.apellido) LIKE LOWER(?)
                    ) THEN 10 ELSE 0 END
                ) AS score_coincidencia
            ", [
                $normalized,
                'publico', $like, $like, $like, $like,
                $like, $like, $like, $like,
                $like, $like, $like,
            ]);
    }

    private function skillMatchQuery($query, string $normalized)
    {
        return $query
            ->from('habilidades_usuario as hu_match')
            ->join('habilidades as h_match', 'h_match.id_habilidad', '=', 'hu_match.habilidad_id')
            ->whereColumn('hu_match.usuario_id', 'usuarios.id_usuario')
            ->whereRaw('hu_match.es_visible = true')
            ->whereRaw('h_match.estado = true')
            ->whereRaw('LOWER(h_match.nombre_normalizado) = ?', [$normalized]);
    }

    private function projectMatchQuery($query, string $like)
    {
        return $query
            ->from('participaciones as par_match')
            ->join('proyectos as p_match', 'p_match.id_proyecto', '=', 'par_match.id_proyecto')
            ->whereColumn('par_match.id_usuario', 'usuarios.id_usuario')
            ->where('par_match.visibilidad', 'publico')
            ->whereNull('par_match.deleted_at')
            ->whereNull('p_match.deleted_at')
            ->where(function ($where) use ($like) {
                $where
                    ->where('p_match.titulo', 'ilike', $like)
                    ->orWhere('p_match.descripcion', 'ilike', $like)
                    ->orWhere('p_match.categoria_proyecto', 'ilike', $like)
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub
                            ->from('uso_tecnologias as ut_match')
                            ->join('tecnologias as t_match', 't_match.id_tecnologia', '=', 'ut_match.id_tecnologia')
                            ->whereColumn('ut_match.id_proyecto', 'p_match.id_proyecto')
                            ->whereNull('ut_match.deleted_at')
                            ->whereNull('t_match.deleted_at')
                            ->where('t_match.nombre', 'ilike', $like);
                    });
            });
    }

    private function experienceMatchQuery($query, string $like)
    {
        return $query
            ->from('experiencias as ex_match')
            ->whereColumn('ex_match.usuario_id', 'usuarios.id_usuario')
            ->whereRaw('ex_match.es_publico = true')
            ->where(function ($where) use ($like) {
                $where
                    ->where('ex_match.cargo', 'ilike', $like)
                    ->orWhere('ex_match.institucion', 'ilike', $like)
                    ->orWhere('ex_match.descripcion', 'ilike', $like)
                    ->orWhere('ex_match.tipo', 'ilike', $like);
            });
    }

    private function normalizeSearchTerm(string $term): string
    {
        return Str::lower(Str::ascii(trim(preg_replace('/\s+/', ' ', $term))));
    }

    private function mapPortfolios(Collection $users): array
    {
        $userIds = $users->pluck('id_usuario')->all();
        $skillsByUser = $this->featuredSkills($userIds);
        $experiencesByUser = $this->featuredExperiences($userIds);

        return $users->map(function ($user) use ($skillsByUser, $experiencesByUser) {
            $updatedAt = $this->parseDate($user->ultima_actividad)
                ?? $this->parseDate($user->perfil_actualizado)
                ?? $this->parseDate($user->usuario_actualizado);

            $skills = $skillsByUser[$user->id_usuario] ?? [];
            $experiences = $experiencesByUser[$user->id_usuario] ?? [];
            $totalSkills = (int) $user->total_habilidades;
            $totalExperiences = (int) $user->total_experiencias;

            return [
                'id_usuario' => (int) $user->id_usuario,
                'nombre' => $user->nombre,
                'apellido' => $user->apellido,
                'nombre_completo' => trim($user->nombre . ' ' . $user->apellido),
                'foto_perfil' => $user->foto_perfil,
                'profesion' => $user->profesion,
                'resumen' => $user->biografia,
                'ciudad' => $user->ciudad,
                'pais' => $user->pais,
                'total_proyectos' => (int) $user->total_proyectos,
                'total_experiencias' => $totalExperiences,
                'total_habilidades' => $totalSkills,
                'fecha_ultima_actualizacion' => $updatedAt?->toIso8601String(),
                'skills_destacadas' => $skills,
                'habilidades_restantes' => max(0, $totalSkills - count($skills)),
                'experiencias_destacadas' => $experiences,
                'experiencias_restantes' => max(0, $totalExperiences - count($experiences)),
                'ruta_portafolio' => '/portafolio/' . $user->id_usuario,
            ];
        })->values()->all();
    }

    private function featuredSkills(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        return HabilidadUsuario::query()
            ->with('habilidad:id_habilidad,nombre,tipo')
            ->whereIn('usuario_id', $userIds)
            ->whereRaw('es_visible = true')
            ->whereHas('habilidad', function ($query) {
                $query->whereRaw('estado = true');
            })
            ->orderByDesc('fecha_modificacion')
            ->orderByDesc('id_habilidad_usuario')
            ->get()
            ->groupBy('usuario_id')
            ->map(function ($items) {
                return $items->take(self::SKILLS_LIMIT)
                    ->map(fn ($item) => $item->habilidad?->nombre)
                    ->filter()
                    ->values()
                    ->all();
            })
            ->all();
    }

    private function featuredExperiences(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        return DB::table('experiencias')
            ->select([
                'usuario_id',
                'id_experiencia',
                'institucion',
                'cargo',
                'descripcion',
                'fecha_inicio',
                'fecha_fin',
                'es_actual',
            ])
            ->whereIn('usuario_id', $userIds)
            ->whereRaw('es_publico = true')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id_experiencia')
            ->get()
            ->groupBy('usuario_id')
            ->map(function ($items) {
                return $items->take(self::EXPERIENCES_LIMIT)
                    ->map(function ($item) {
                        return [
                            'id_experiencia' => (int) $item->id_experiencia,
                            'institucion' => $item->institucion,
                            'cargo' => $item->cargo,
                            'descripcion' => $item->descripcion,
                            'fecha_inicio' => $item->fecha_inicio ? Carbon::parse($item->fecha_inicio)->format('Y-m-d') : null,
                            'fecha_fin' => $item->fecha_fin ? Carbon::parse($item->fecha_fin)->format('Y-m-d') : null,
                            'es_actual' => (bool) $item->es_actual,
                        ];
                    })
                    ->values()
                    ->all();
            })
            ->all();
    }

    private function parseDate($value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
