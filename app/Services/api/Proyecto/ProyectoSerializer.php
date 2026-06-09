<?php

namespace App\Services\api\Proyecto;

use App\Services\api\ContenidoTraduccionService;
use App\Services\api\ProfileImageVariantService;
use Illuminate\Support\Facades\DB;


class ProyectoSerializer
{
    private const TIPO_IMAGEN = 'imagen';

    public function __construct(
        private readonly ProyectoPermisoService $proyectoPermisoService,
        private readonly ProfileImageVariantService $profileImageVariants,
        private readonly ContenidoTraduccionService $traduccionService,
    ) {}

    public function serialize(
        array $project,
        ?array $indexContext = null,
        string $lang = 'es'
    ): array
    {
        $id = (int) $project['id_proyecto'];
        $project = $this->traducirProyecto($project, $lang);

        $evidencias = $indexContext !== null
            ? collect($indexContext['evidencias'][$id] ?? [])
            : DB::table('proyecto_evidencias')
                ->where('id_proyecto', $id)
                ->whereNull('deleted_at')
                ->orderBy('orden')
                ->orderBy('id_evidencia')
                ->get()
                ->map(fn ($evidence) => $this->serializeEvidence($evidence))
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

        $repositorios = $repositoriosDetalle->pluck('url_repositorio')->filter()->values();

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

        $tecnologiaNombres = $tecnologias->pluck('nombre')->filter()->values();
        $participantesCount = $indexContext !== null
            ? (int) ($indexContext['participantes_count'][$id] ?? 0)
            : DB::table('participaciones')->where('id_proyecto', $id)->whereNull('deleted_at')->count();

        $currentUserId = (int) ($project['participacion_id_usuario'] ?? 0);
        $permissions = $indexContext !== null
            ? ($indexContext['permisos'][$id] ?? $this->proyectoPermisoService->defaultPermissions())
            : ($currentUserId > 0
                ? $this->proyectoPermisoService->resolve($currentUserId, $id)
                : $this->proyectoPermisoService->defaultPermissions());
        $configuration = $indexContext !== null
            ? ($indexContext['configuraciones'][$id] ?? $this->proyectoPermisoService->defaultConfiguration())
            : $this->proyectoPermisoService->configuration($id);

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

    public function loadIndexContext($projects, int $userId): array
    {
        $ids = $projects->pluck('id_proyecto')->map(fn ($id) => (int) $id)->filter()->values()->all();

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
            ->map(fn ($items) => $items->map(fn ($item) => $this->serializeEvidence($item))->values()->all())
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
                $array = (array) $item;
                unset($array['group_id_proyecto']);

                return $array;
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
                $array = (array) $item;
                unset($array['group_id_proyecto']);

                return $array;
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
                (int) $row->id_proyecto => $this->proyectoPermisoService
                    ->normalizeConfiguration((array) $row, (int) $row->id_proyecto),
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
            $config = $configuraciones[$id] ?? $this->proyectoPermisoService->defaultConfiguration();
            $config['id_proyecto'] = $id;
            $configuraciones[$id] = $config;
            $permisos[$id] = $this->proyectoPermisoService->resolveFromLoadedData(
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

    private function serializeEvidence(object|array $evidence): array
    {
        $array = (array) $evidence;
        unset($array['group_id_proyecto']);
        $array['archivo_url'] = $array['url'] ?? null;

        if (in_array(strtolower((string) ($array['tipo'] ?? '')), [self::TIPO_IMAGEN, 'captura'], true)) {
            $variants = $this->profileImageVariants->getProjectVariantUrls($array['url'] ?? null);
            $array['imagen_card_url'] = $variants['card'] ?? null;
            $array['imagen_detail_url'] = $variants['detail'] ?? null;
        }

        return $array;
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

    private function traducirProyecto(array $project, string $lang): array
    {
        $project = $this->traduccionService->traducirArray(
            $project,
            'proyecto',
            $project['id_proyecto'] ?? null,
            ['titulo', 'descripcion'],
            $lang
        );

        return $this->traduccionService->traducirArray(
            $project,
            'participacion',
            $project['id_participacion'] ?? null,
            ['rol', 'descripcion_aporte'],
            $lang
        );
    }

}
