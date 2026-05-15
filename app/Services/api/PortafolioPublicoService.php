<?php

namespace App\Services\api;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class PortafolioPublicoService
{
    public function __construct(
        private readonly PersonalizacionPortafolioService $personalizacionService
    ) {
    }

    public function getByUser(int $userId): ?array
    {
        $usuario = Usuario::with(['perfil', 'visibilidades'])->find($userId);

        if (! $usuario || ! $usuario->perfil || ! $this->toBoolean($usuario->perfil->es_publico, true)) {
            return null;
        }

        return [
            'perfil' => $this->serializePerfil($usuario),
            'redes' => $this->getRedes($userId),
            'habilidades' => $this->getHabilidades($userId),
            'experiencias' => $this->getExperiencias($userId),
            'proyectos' => $this->getProyectos($userId),
            'config' => $this->personalizacionService->getByUser($userId) ?? (object) [],
        ];
    }

    private function serializePerfil(Usuario $usuario): array
    {
        $perfil = $usuario->perfil;
        $visibilidadRaw = $usuario->visibilidades
            ->pluck('visible', 'campo')
            ->toArray();

        $visibilidad = [
            'nombre' => true,
            'correo' => $this->visible($visibilidadRaw, 'correo'),
            'telefono' => $this->visible($visibilidadRaw, 'telefono'),
            'biografia' => $this->visible($visibilidadRaw, 'biografia'),
            'pais' => $this->visible($visibilidadRaw, 'pais'),
            'ciudad' => $this->visible($visibilidadRaw, 'ciudad'),
            'profesion' => $this->visible($visibilidadRaw, 'profesion'),
        ];

        return [
            'id' => $usuario->id_usuario,
            'id_usuario' => $usuario->id_usuario,
            'nombre' => $usuario->nombre,
            'apellido' => $usuario->apellido,
            'correo' => $visibilidad['correo'] ? $usuario->correo : null,
            'telefono' => $visibilidad['telefono'] ? $usuario->telefono : null,
            'profesion' => $visibilidad['profesion'] ? $perfil?->profesion : null,
            'biografia' => $visibilidad['biografia'] ? $perfil?->biografia : null,
            'ciudad' => $visibilidad['ciudad'] ? $perfil?->ciudad : null,
            'pais' => $visibilidad['pais'] ? $perfil?->pais : null,
            'foto_perfil' => $perfil?->foto_perfil,
            'foto_fondo' => $perfil?->foto_fondo,
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

    private function getHabilidades(int $userId): array
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
            ->map(function ($item) {
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
                        'nombre' => $row['nombre'],
                        'nombre_normalizado' => $row['nombre_normalizado'],
                        'tipo' => $row['tipo'],
                        'descripcion' => $row['descripcion'],
                        'estado' => $row['estado'],
                    ],
                ];
            })
            ->all();
    }

    private function getExperiencias(int $userId): array
    {
        return DB::table('experiencias')
            ->where('usuario_id', $userId)
            ->whereRaw('es_publico IS TRUE')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id_experiencia')
            ->get()
            ->map(fn ($item) => (array) $item)
            ->all();
    }

    private function getProyectos(int $userId): array
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
                'p.rol',
                'p.descripcion_aporte',
                'p.visibilidad',
                'p.fecha_inicio as part_fecha_inicio',
                'p.fecha_fin as part_fecha_fin'
            )
            ->get()
            ->map(fn ($project) => $this->serializeProject((array) $project))
            ->all();
    }

    private function serializeProject(array $project): array
    {
        $id = (int) $project['id_proyecto'];

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

                return $arr;
            })
            ->values()
            ->all();

        $repositorios = DB::table('proyecto_repositorios')
            ->where('id_proyecto', $id)
            ->where('proveedor', 'github')
            ->whereNull('deleted_at')
            ->orderBy('id_proyecto_repositorio')
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

        $participantesCount = DB::table('participaciones')
            ->where('id_proyecto', $id)
            ->whereNull('deleted_at')
            ->count();

        return [
            ...$project,
            'id' => $id,
            'id_proyecto' => $id,
            'url_repositorios' => $repositorios->all(),
            'url_repositorio' => $repositorios->first() ?? '',
            'etiquetas' => $tecnologiaNombres->all(),
            'tecnologias' => $tecnologiaNombres->all(),
            'tecnologias_detalle' => $tecnologias->map(fn ($tech) => (array) $tech)->all(),
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

    private function visible(array $visibilidadRaw, string $campo): bool
    {
        return $this->toBoolean($visibilidadRaw[$campo] ?? false);
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
