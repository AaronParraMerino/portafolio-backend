<?php

namespace App\Services\api;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class PortafolioPublicoService
{
    public function __construct(
        private readonly PersonalizacionPortafolioService $personalizacionService,
        private readonly ProfileImageVariantService $profileImageVariants,
        private readonly ContenidoTraduccionService $traduccionService
    ) {
    }

    public function getByUser(int $userId, string $lang = 'es'): ?array
    {
        $lang = $this->traduccionService->normalizarIdioma($lang);

        $usuario = Usuario::with(['perfil', 'visibilidades'])->find($userId);

        if (
            ! $usuario
            || ! in_array($usuario->estado, ['activo', 'pausado'], true)
            || ! $usuario->perfil
            || ! $this->toBoolean($usuario->perfil->es_publico, true)
        ) {
            return null;
        }

        $configuracion = $this->personalizacionService->getByUser($userId) ?? [];

        return [
            'perfil' => $this->serializePerfil(
                $usuario,
                $configuracion['visibilidad']['perfil'] ?? [],
                $lang
            ),
            'redes' => $this->getRedes($userId),
            'habilidades' => $this->getHabilidades($userId, $lang),
            'experiencias' => $this->getExperiencias($userId, $lang),
            'proyectos' => $this->getProyectos($userId, $lang),
            'config' => $configuracion ?: (object) [],
        ];
    }

    private function serializePerfil(Usuario $usuario, array $configVisibility = [], string $lang = 'es'): array
    {
        $perfil = $usuario->perfil;
        $fotoVariantes = $this->profileImageVariants->getVariantUrls($perfil?->foto_perfil);
        $bannerVariantes = $this->profileImageVariants->getBannerVariantUrls($perfil?->foto_fondo);
        $visibilidadRaw = $usuario->visibilidades
            ->pluck('visible', 'campo')
            ->toArray();

        $nombreVisible = $this->visibleFromConfig($configVisibility, 'nombre', true);
        $visibilidad = [
            'nombre' => $nombreVisible,
            'correo' => $this->visible($visibilidadRaw, 'correo'),
            'telefono' => $this->visible($visibilidadRaw, 'telefono'),
            'biografia' => $this->visible($visibilidadRaw, 'biografia'),
            'pais' => $this->visible($visibilidadRaw, 'pais'),
            'ciudad' => $this->visible($visibilidadRaw, 'ciudad'),
            'profesion' => $this->visible($visibilidadRaw, 'profesion'),
        ];

        $perfilId = $perfil?->id_perfil ?? $usuario->id_usuario;

        $profesion = $this->traduccionService->traducirCampo(
            'perfil',
            $perfilId,
            'profesion',
            $perfil?->profesion,
            $lang
        );

        $biografia = $this->traduccionService->traducirCampo(
            'perfil',
            $perfilId,
            'biografia',
            $perfil?->biografia,
            $lang
        );

        return [
            'id' => $usuario->id_usuario,
            'id_usuario' => $usuario->id_usuario,
            'nombre' => $nombreVisible ? $usuario->nombre : null,
            'apellido' => $nombreVisible ? $usuario->apellido : null,
            'correo' => $visibilidad['correo'] ? $usuario->correo : null,
            'telefono' => $visibilidad['telefono'] ? $usuario->telefono : null,
            'profesion' => $visibilidad['profesion'] ? $profesion : null,
            'biografia' => $visibilidad['biografia'] ? $biografia : null,
            'ciudad' => $visibilidad['ciudad'] ? $perfil?->ciudad : null,
            'pais' => $visibilidad['pais'] ? $perfil?->pais : null,
            'foto_perfil' => $perfil?->foto_perfil,
            'foto_perfil_medium_url' => $fotoVariantes['medium'] ?? null,
            'foto_perfil_small_url' => $fotoVariantes['small'] ?? null,
            'foto_perfil_thumb_url' => $fotoVariantes['thumb'] ?? null,
            'foto_fondo' => $perfil?->foto_fondo,
            'foto_fondo_medium_url' => $bannerVariantes['medium'] ?? null,
            'foto_fondo_small_url' => $bannerVariantes['small'] ?? null,
            'es_publico' => $this->toBoolean($perfil?->es_publico, true),
            'portfolio_publico' => $this->toBoolean($perfil?->es_publico, true),
            'visibilidad' => $visibilidad,
        ];
    }

    private function getRedes(int $userId): array
    {
        return DB::table('enlaces')
            ->where('id_usuario', $userId)
            ->whereRaw('es_visible IS TRUE')
            ->orderBy('id_enlace')
            ->get()
            ->map(fn ($item) => (array) $item)
            ->all();
    }

    private function getHabilidades(int $userId, string $lang = 'es'): array
    {
        return DB::table('habilidades_usuario as hu')
            ->join('habilidades as h', 'h.id_habilidad', '=', 'hu.habilidad_id')
            ->where('hu.usuario_id', $userId)
            ->whereRaw('hu.es_visible IS TRUE')
            ->whereRaw('h.estado IS TRUE')
            ->orderByDesc('hu.fecha_modificacion')
            ->orderBy('h.nombre')
            ->select(
                'hu.id_habilidad_usuario',
                'hu.usuario_id',
                'hu.habilidad_id',
                'hu.nivel',
                'hu.es_visible',
                'hu.fecha_modificacion',
                'h.id_habilidad',
                'h.nombre',
                'h.nombre_normalizado',
                'h.tipo',
                'h.descripcion',
                'h.estado'
            )
            ->get()
            ->map(function ($item) use ($lang) {
                $row = (array) $item;

                return [
                    'id_habilidad_usuario' => $row['id_habilidad_usuario'],
                    'usuario_id' => $row['usuario_id'],
                    'habilidad_id' => $row['habilidad_id'],
                    'nivel' => $row['nivel'],
                    'es_visible' => $row['es_visible'],
                    'fecha_modificacion' => $row['fecha_modificacion'],
                    'habilidad' => [
                        'id_habilidad' => $row['id_habilidad'],
                        'nombre' => $this->traduccionService->traducirCampo(
                            'habilidad',
                            $row['id_habilidad'],
                            'nombre',
                            $row['nombre'],
                            $lang
                        ),
                        'nombre_normalizado' => $row['nombre_normalizado'],
                        'tipo' => $row['tipo'],
                        'descripcion' => $this->traduccionService->traducirCampo(
                            'habilidad',
                            $row['id_habilidad'],
                            'descripcion',
                            $row['descripcion'],
                            $lang
                        ),
                        'estado' => $row['estado'],
                    ],
                ];
            })
            ->all();
    }

    private function getExperiencias(int $userId, string $lang = 'es'): array
    {
        return DB::table('experiencias')
            ->where('usuario_id', $userId)
            ->whereRaw('es_publico IS TRUE')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id_experiencia')
            ->get()
            ->map(function ($item) use ($lang) {
                $experiencia = (array) $item;
                $id = $experiencia['id_experiencia'] ?? null;

                return $this->traduccionService->traducirArray(
                    $experiencia,
                    'experiencia',
                    $id,
                    ['cargo', 'descripcion'],
                    $lang
                );
            })
            ->all();
    }

    private function getProyectos(int $userId, string $lang = 'es'): array
    {
        return DB::table('participaciones as p')
            ->join('proyectos as pr', 'pr.id_proyecto', '=', 'p.id_proyecto')
            ->where('p.id_usuario', $userId)
            ->where('p.visibilidad', 'publico')
            ->whereNull('p.deleted_at')
            ->whereNull('pr.deleted_at')
            ->orderByDesc('pr.updated_at')
            ->select(
                'pr.*',
                'p.id_participacion',
                'p.rol',
                'p.descripcion_aporte',
                'p.visibilidad',
                'p.fecha_inicio as part_fecha_inicio',
                'p.fecha_fin as part_fecha_fin'
            )
            ->get()
            ->map(fn ($project) => $this->serializeProject((array) $project, $lang))
            ->all();
    }

    private function serializeProject(array $project, string $lang = 'es'): array
    {
        $id = (int) $project['id_proyecto'];

        $project = $this->traduccionService->traducirArray(
            $project,
            'proyecto',
            $id,
            ['titulo', 'descripcion'],
            $lang
        );

        $idParticipacion = $project['id_participacion'] ?? null;

        $project = $this->traduccionService->traducirArray(
            $project,
            'participacion',
            $idParticipacion,
            ['rol', 'descripcion_aporte'],
            $lang
        );        

        $evidencias = DB::table('proyecto_evidencias')
            ->where('id_proyecto', $id)
            ->whereRaw('es_visible IS TRUE')
            ->whereNull('deleted_at')
            ->orderBy('orden')
            ->orderBy('id_evidencia')
            ->get()
            ->map(function ($ev) {
                $arr = (array) $ev;
                $arr['archivo_url'] = $arr['url'] ?? null;
                if (in_array(strtolower((string) ($arr['tipo'] ?? '')), ['imagen', 'captura'], true)) {
                    $variants = $this->profileImageVariants->getProjectVariantUrls($arr['url'] ?? null);
                    $arr['imagen_card_url'] = $variants['card'] ?? null;
                    $arr['imagen_detail_url'] = $variants['detail'] ?? null;
                }

                return $arr;
            })
            ->values()
            ->all();

        $repositoriosDetalle = DB::table('proyecto_repositorios as pr')
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

        $tecnologias = DB::table('uso_tecnologias as ut')
            ->join('tecnologias as t', 't.id_tecnologia', '=', 'ut.id_tecnologia')
            ->where('ut.id_proyecto', $id)
            ->whereRaw('ut.es_visible IS TRUE')
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

        $participantes = $this->getParticipantesPublicos($id, $lang);
        $participantesCount = count($participantes);

        return [
            ...$project,
            'id' => $id,
            'id_proyecto' => $id,
            'url_repositorios' => $repositorios->all(),
            'url_repositorio' => $repositorios->first() ?? '',
            'repositorios_detalle' => $repositoriosDetalle->all(),
            'etiquetas' => $tecnologiaNombres->all(),
            'tecnologias' => $tecnologiaNombres->all(),
            'tecnologias_detalle' => $tecnologias->map(fn ($tech) => (array) $tech)->all(),
            'participantes' => $participantes,
            'participantes_count' => $participantesCount,
            'participacion' => [
                'rol' => $project['rol'] ?? null,
                'descripcion_aporte' => $project['descripcion_aporte'] ?? null,
                'visibilidad' => $project['visibilidad'] ?? 'publico',
                'fecha_inicio' => $project['part_fecha_inicio'] ?? null,
                'fecha_fin' => $project['part_fecha_fin'] ?? null,
            ],
            'evidencias' => $evidencias,
        ];
    }

    private function getParticipantesPublicos(int $projectId, string $lang = 'es'): array
    {
        $mostrarSinValidacion = DB::table('proyecto_configuraciones')
            ->where('id_proyecto', $projectId)
            ->value('visibilidad_usuario_sin_validacion') !== 'oculto';

        $repos = DB::table('proyecto_repositorios as pr')
            ->leftJoin('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->where('pr.id_proyecto', $projectId)
            ->where('pr.proveedor', 'github')
            ->whereNull('pr.deleted_at')
            ->select('rg.id_repositorio_github')
            ->get();

        $repoGithubIds = $repos
            ->pluck('id_repositorio_github')
            ->filter()
            ->values();

        $rows = DB::table('participaciones as p')
            ->join('usuarios as u', 'u.id_usuario', '=', 'p.id_usuario')
            ->leftJoin('perfiles as pe', 'pe.usuario_id', '=', 'u.id_usuario')
            ->leftJoin('cuentas_oauth as co', function ($join) {
                $join->on('co.usuario_id', '=', 'u.id_usuario')
                    ->where('co.provider', '=', 'github');
            })
            ->where('p.id_proyecto', $projectId)
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

        $userIds = $rows->pluck('id_usuario')->filter()->values();
        $validaciones = $repoGithubIds->isEmpty() || $userIds->isEmpty()
            ? collect()
            : DB::table('usuario_repositorio_validaciones')
                ->whereIn('id_usuario', $userIds->all())
                ->whereIn('id_repositorio_github', $repoGithubIds->all())
                ->get()
                ->groupBy('id_usuario');

        return $rows
            ->map(function ($row) use ($validaciones, $mostrarSinValidacion, $lang) {
                $userValidaciones = $validaciones->get($row->id_usuario, collect());
                $validacion = $userValidaciones->first(fn ($item) => $this->toBoolean($item->validado))
                    ?? $userValidaciones->first();
                $validado = $this->toBoolean($validacion?->validado ?? $row->participacion_validada ?? false);

                if (! $validado && ! $mostrarSinValidacion) {
                    return null;
                }

                $tipoParticipante = $validado
                    ? 'usuario_github_validado'
                    : 'usuario_sin_validacion_github';

                $rolTraducido = $this->traduccionService->traducirCampo(
                    'participacion',
                    $row->id_participacion,
                    'rol',
                    $row->rol,
                    $lang
                );

                $descripcionAporteTraducida = $this->traduccionService->traducirCampo(
                    'participacion',
                    $row->id_participacion,
                    'descripcion_aporte',
                    $row->descripcion_aporte,
                    $lang
                );

                return [
                    'id' => 'participacion-' . $row->id_participacion,
                    'id_participacion' => (int) $row->id_participacion,
                    'id_usuario' => (int) $row->id_usuario,
                    'nombre' => trim(($row->nombre ?? '') . ' ' . ($row->apellido ?? '')),
                    'email' => $row->correo,
                    'rol' => $rolTraducido,
                    'descripcion_aporte' => $descripcionAporteTraducida,
                    'es_propietario' => (bool) $row->es_propietario,
                    'foto_perfil' => $row->foto_perfil,
                    'avatar_thumb_url' => $this->profileImageVariants->getVariantUrl($row->foto_perfil, 'thumb'),
                    'github_avatar_url' => $row->github_foto_url,
                    'avatar_url' => $row->foto_perfil ?: $row->github_foto_url,
                    'source' => 'sistema',
                    'tipo_participante' => $tipoParticipante,
                    'origen_participante' => $tipoParticipante,
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
            })
            ->filter()
            ->unique(fn ($item) => ! empty($item['id_usuario'])
                ? 'usuario:' . $item['id_usuario']
                : 'github-id:' . ($item['github_id'] ?? $item['id'])
            )
            ->sortBy([
                fn ($a, $b) => ((bool) ($b['es_propietario'] ?? false)) <=> ((bool) ($a['es_propietario'] ?? false)),
                fn ($a, $b) => strcmp((string) ($a['nombre'] ?? ''), (string) ($b['nombre'] ?? '')),
            ])
            ->values()
            ->all();
    }

    private function visible(array $visibilidadRaw, string $campo): bool
    {
        return $this->toBoolean($visibilidadRaw[$campo] ?? false);
    }

    private function visibleFromConfig(array $visibility, string $campo, bool $fallback = true): bool
    {
        if (! array_key_exists($campo, $visibility)) {
            return $fallback;
        }

        return $this->toBoolean($visibility[$campo], $fallback);
    }

    private function toBoolean(mixed $value, bool $fallback = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 't', 'yes', 'on', 'publico'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'f', 'no', 'off', 'privado'], true)) {
                return false;
            }
        }

        return $fallback;
    }
}
