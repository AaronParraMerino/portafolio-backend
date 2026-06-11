<?php

namespace App\Services\api;

use App\Models\Habilidad;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusquedaService
{
    /** Ejecuta la busqueda principal */
    public function search(array $f, int $perPage = 12)
    {
        $filtros = $this->filtrosActivos($f);
        $q = $this->baseSearchQuery();

        $this->applyBaseFilters($q, $f, $filtros);
        $this->applyFilteredSubqueries($q, $f, $filtros);

        $idsFinalistas = $this->getFinalistIds($q);
        $ctxOrden = $this->contextoOrden($f, $filtros);

        $this->applyUnfilteredSubqueries($q, $idsFinalistas, $f, $filtros);
        $this->applyOrderSubqueries($q, $idsFinalistas, $f, $filtros, $ctxOrden);
        $this->applyRelatedItemsSubquery($q, $idsFinalistas, $f);
        $this->addSearchSelects($q, $f, $filtros['query']);
        $this->applyOrdering($q, $f, $filtros);

        return $q->paginate($perPage);
    }

    /** Detecta filtros activos */
    private function filtrosActivos(array $f): array
    {
        return [
            'query' => !empty(trim(data_get($f, 'query', ''))),
            'usuario' => $this->tieneValores(data_get($f, 'usuario')),
            'habilidades' => $this->tieneValores(data_get($f, 'habilidades')),
            'experiencia' => $this->tieneValores(data_get($f, 'experiencia')),
            'proyectos' => $this->tieneValores(data_get($f, 'proyectos')),
        ];
    }

    /** Construye el contexto usado solo para ordenar */
    private function contextoOrden(array $f, array $filtros): array
    {
        $queryTokens = $filtros['query'] ? $this->queryTokens((string) data_get($f, 'query', '')) : [];

        $skillTokens = array_merge(
            $this->normalize((array) data_get($f, 'habilidades.tecnicas.items', [])),
            $this->normalize((array) data_get($f, 'habilidades.blandas.items', [])),
            $this->tokensEnHabilidades($queryTokens)
        );

        $skillTechTokens = array_merge(
            $this->normalize((array) data_get($f, 'habilidades.tecnicas.items', [])),
            $this->tokensEnHabilidades($queryTokens, 'tecnica')
        );

        $expTokens = array_merge(
            $this->experienceCargoTokens($f),
            $this->tokensEnExperiencias($queryTokens)
        );

        $projectTokens = array_merge(
            $this->normalize((array) data_get($f, 'proyectos.tecnologias', [])),
            $this->tokensEnTecnologiasProyecto($queryTokens)
        );

        return [
            'query_tokens' => $queryTokens,
            'skill_tokens' => $this->unique($skillTokens),
            'skill_tech_tokens' => $this->unique($skillTechTokens),
            'exp_tokens' => $this->unique($expTokens),
            'project_tokens' => $this->unique($projectTokens),
        ];
    }

    /** Construye la consulta base */
    private function baseSearchQuery()
    {
        return DB::table('usuarios')
            ->join('perfiles', 'perfiles.usuario_id', '=', 'usuarios.id_usuario')
            ->leftJoin('visibilidad_campos as vis_profesion', function ($join) {
                $join->on('vis_profesion.usuario_id', '=', 'usuarios.id_usuario')
                    ->whereRaw("vis_profesion.campo = 'profesion'");
            })
            ->leftJoin('visibilidad_campos as vis_ciudad', function ($join) {
                $join->on('vis_ciudad.usuario_id', '=', 'usuarios.id_usuario')
                    ->whereRaw("vis_ciudad.campo = 'ciudad'");
            })
            ->leftJoin('visibilidad_campos as vis_pais', function ($join) {
                $join->on('vis_pais.usuario_id', '=', 'usuarios.id_usuario')
                    ->whereRaw("vis_pais.campo = 'pais'");
            })
            ->whereIn('usuarios.estado', ['activo', 'pausado'])
            ->whereRaw('perfiles.es_publico IS TRUE');
    }

    /** Aplica filtros base */
    private function applyBaseFilters($q, array $f, array $filtros): void
    {
        if ($filtros['query']) {
            $this->applyQueryFilter($q, $f);
        }

        if ($filtros['usuario']) {
            $this->applyUsuarioFilter($q, $f);
        }
    }

    /** Une subconsultas filtradas */
    private function applyFilteredSubqueries($q, array $f, array $filtros): void
    {
        if ($filtros['habilidades']) {
            $this->leftJoinSubquery($q, $this->subHabilidades($f), 'h');
            $q->whereRaw('COALESCE(h.total, 0) > 0');
        }

        if ($filtros['experiencia']) {
            $this->leftJoinSubquery($q, $this->subExperiencias($f), 'e');
            $q->whereRaw('COALESCE(e.total, 0) > 0');
        }

        if ($filtros['proyectos']) {
            $this->leftJoinSubquery($q, $this->subProyectos($f), 'p');
            $q->whereRaw('COALESCE(p.total, 0) > 0');
        }
    }

    /** Obtiene usuarios finalistas */
    private function getFinalistIds($q): array
    {
        return (clone $q)->pluck('usuarios.id_usuario')->toArray();
    }

    /** Une subconsultas sin filtro */
    private function applyUnfilteredSubqueries($q, array $idsFinalistas, array $f, array $filtros): void
    {
        if (!$filtros['habilidades']) {
            $this->leftJoinSubquery($q, $this->subHabilidadesSinFiltro($idsFinalistas, $f), 'h');
        }

        if (!$filtros['experiencia']) {
            $this->leftJoinSubquery($q, $this->subExperienciasSinFiltro($idsFinalistas, $f), 'e');
        }

        if (!$filtros['proyectos']) {
            $this->leftJoinSubquery($q, $this->subProyectosSinFiltro($idsFinalistas, $f), 'p');
        }
    }

    /** Une subconsultas usadas exclusivamente para ordenar */
    private function applyOrderSubqueries($q, array $idsFinalistas, array $f, array $filtros, array $ctx): void
    {
        $h = $filtros['habilidades']
            ? $this->subHabilidades($f)
            : (!empty($ctx['skill_tokens'])
                ? $this->subHabilidadesPorTokens($idsFinalistas, $f, $ctx['skill_tokens'])
                : (!empty($ctx['project_tokens'])
                    ? $this->subHabilidadesPorTokens($idsFinalistas, $f, $ctx['project_tokens'], 'tecnica')
                    : $this->subHabilidadesSinFiltro($idsFinalistas, $f)));

        $e = $filtros['experiencia']
            ? $this->subExperiencias($f)
            : (!empty($ctx['exp_tokens'])
                ? $this->subExperienciasPorTokens($idsFinalistas, $f, $ctx['exp_tokens'])
                : $this->subExperienciasSinFiltro($idsFinalistas, $f));

        $p = $filtros['proyectos']
            ? $this->subProyectos($f)
            : (!empty($ctx['project_tokens'])
                ? $this->subProyectosPorTokens($idsFinalistas, $f, $ctx['project_tokens'])
                : (!empty($ctx['skill_tech_tokens'])
                    ? $this->subProyectosPorTokens($idsFinalistas, $f, $ctx['skill_tech_tokens'])
                    : $this->subProyectosSinFiltro($idsFinalistas, $f)));

        $this->leftJoinSubquery($q, $h, 'oh');
        $this->leftJoinSubquery($q, $e, 'oe');
        $this->leftJoinSubquery($q, $p, 'op');
    }

    /** Une una subconsulta por usuario */
    private function leftJoinSubquery($q, $subquery, string $alias): void
    {
        $q->leftJoinSub($subquery, $alias, function ($join) use ($alias) {
            $join->on("{$alias}.usuario_id", '=', 'usuarios.id_usuario');
        });
    }

    /** Define las columnas de respuesta */
    private function addSearchSelects($q, array $f, bool $tieneQuery): void
    {
        $q->select([
            'usuarios.id_usuario',
            'usuarios.nombre',
            'usuarios.apellido',
            'perfiles.foto_perfil',
            DB::raw('CASE WHEN COALESCE(vis_profesion.visible, false) = true THEN perfiles.profesion ELSE NULL END AS profesion'),
            DB::raw('CASE WHEN COALESCE(vis_ciudad.visible, false) = true THEN perfiles.ciudad ELSE NULL END AS ciudad'),
            DB::raw('CASE WHEN COALESCE(vis_pais.visible, false) = true THEN perfiles.pais ELSE NULL END AS pais'),
            DB::raw('COALESCE(h.total, 0) AS habilidades_relacionadas'),
            DB::raw("COALESCE(r.tecnologias, '') AS tecnologias_relacionadas"),
            DB::raw('COALESCE(e.total, 0) AS experiencias_relacionadas'),
            DB::raw('COALESCE(p.total, 0) AS proyectos_relacionados'),
        ]);

        if ($tieneQuery) {
            $this->addQueryRelevanceSelect($q, $f);
            return;
        }

        $q->selectRaw('0 AS relevancia_textual');
    }

    /** Verifica si existe algun valor */
    private function tieneValores($valor): bool
    {
        if ($valor === null || $valor === '' || $valor === false) {
            return false;
        }

        if (is_array($valor)) {
            foreach ($valor as $v) {
                if ($this->tieneValores($v)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /** Aplica busqueda textual general */
    private function applyQueryFilter($q, array $f): void
    {
        $query = trim((string) data_get($f, 'query', ''));

        if ($query === '') {
            return;
        }

        $tokens = $this->queryTokens($query);
        $fechaDesde = $this->fechaDesde($f);

        if (empty($tokens)) {
            return;
        }

        $q->where(function ($w) use ($tokens, $fechaDesde) {
            foreach ($tokens as $token) {
                $like = "%{$token}%";

                $w->orWhereRaw(
                    "LOWER(usuarios.nombre || ' ' || usuarios.apellido) LIKE ?",
                    [$like]
                )
                    ->orWhere(function ($q) use ($like) {
                        $q->whereRaw('COALESCE(vis_profesion.visible, false) = true')
                            ->where('perfiles.profesion', 'ilike', $like);
                    })
                    ->orWhere(function ($q) use ($like) {
                        $q->whereRaw('COALESCE(vis_ciudad.visible, false) = true')
                            ->where('perfiles.ciudad', 'ilike', $like);
                    })
                    ->orWhere(function ($q) use ($like) {
                        $q->whereRaw('COALESCE(vis_pais.visible, false) = true')
                            ->where('perfiles.pais', 'ilike', $like);
                    })
                    ->orWhereExists(function ($s) use ($like, $fechaDesde) {
                        $s->from('habilidades_usuario as hu')
                            ->join('habilidades as hb', 'hb.id_habilidad', '=', 'hu.habilidad_id')
                            ->whereColumn('hu.usuario_id', 'usuarios.id_usuario')
                            ->whereRaw('hu.es_visible IS TRUE')
                            ->whereRaw('hb.estado IS TRUE')
                            ->where(function ($q) use ($like) {
                                $q->where('hb.nombre', 'ilike', $like)
                                    ->orWhere('hb.nombre_normalizado', 'ilike', $like);
                            })
                            ->when($fechaDesde, fn($q) => $q->whereDate('hu.created_at', '>=', $fechaDesde));
                    })
                    ->orWhereExists(function ($s) use ($like, $fechaDesde) {
                        $s->from('experiencias as ex')
                            ->whereColumn('ex.usuario_id', 'usuarios.id_usuario')
                            ->whereRaw('ex.es_publico IS TRUE')
                            ->where('ex.cargo', 'ilike', $like)
                            ->when($fechaDesde, fn($q) => $q->whereDate('ex.fecha_inicio', '>=', $fechaDesde));
                    })
                    ->orWhereExists(function ($s) use ($like, $fechaDesde) {
                        $s->from('participaciones as par')
                            ->join('proyectos as p2', 'p2.id_proyecto', '=', 'par.id_proyecto')
                            ->join('uso_tecnologias as ut', 'ut.id_proyecto', '=', 'p2.id_proyecto')
                            ->join('tecnologias as t', 't.id_tecnologia', '=', 'ut.id_tecnologia')
                            ->whereColumn('par.id_usuario', 'usuarios.id_usuario')
                            ->where('par.visibilidad', 'publico')
                            ->whereNull('par.deleted_at')
                            ->whereNull('p2.deleted_at')
                            ->whereNull('ut.deleted_at')
                            ->whereRaw('ut.es_visible IS TRUE')
                            ->where('t.nombre', 'ilike', $like)
                            ->when($fechaDesde, fn($q) => $q->whereDate('par.fecha_inicio', '>=', $fechaDesde));
                    });
            }
        });
    }

    /** Aplica filtros de usuario */
    private function applyUsuarioFilter($q, array $f): void
    {
        $u = data_get($f, 'usuario', []);

        if (!empty($u['nombre'])) {
            $q->whereRaw(
                "LOWER(usuarios.nombre || ' ' || usuarios.apellido) LIKE LOWER(?)",
                ["%{$u['nombre']}%"]
            );
        }

        if (!empty($u['ciudad'])) {
            $q->whereRaw('COALESCE(vis_ciudad.visible, false) = true')
                ->whereIn(DB::raw('LOWER(perfiles.ciudad)'), $this->normalize($u['ciudad']));
        }

        if (!empty($u['pais'])) {
            $q->whereRaw('COALESCE(vis_pais.visible, false) = true')
                ->whereIn(DB::raw('LOWER(perfiles.pais)'), $this->normalize($u['pais']));
        }

        if (!empty($u['profesion'])) {
            $q->whereRaw('COALESCE(vis_profesion.visible, false) = true')
                ->where(function ($w) use ($u) {
                    foreach ($u['profesion'] as $p) {
                        $w->orWhere('perfiles.profesion', 'ilike', "%{$p}%");
                    }
                });
        }
    }

    /** Construye subconsulta de habilidades filtradas */
    private function subHabilidades(array $f)
    {
        $tecItems = data_get($f, 'habilidades.tecnicas.items', []);
        $tecNiveles = data_get($f, 'habilidades.tecnicas.niveles', []);
        $blaItems = data_get($f, 'habilidades.blandas.items', []);
        $blaNiveles = data_get($f, 'habilidades.blandas.niveles', []);
        $fechaDesde = $this->fechaDesde($f);
        $hayTecnicas = !empty($tecItems) || !empty($tecNiveles);
        $hayBlandas = !empty($blaItems) || !empty($blaNiveles);

        $sub = DB::table('habilidades_usuario as hu')
            ->join('habilidades as hb', 'hb.id_habilidad', '=', 'hu.habilidad_id')
            ->select(
                'hu.usuario_id',
                DB::raw('COUNT(*) as total'),
                DB::raw("STRING_AGG(hb.nombre, ', ') as tecnologias")
            )
            ->whereRaw('hu.es_visible IS TRUE')
            ->whereRaw('hb.estado IS TRUE');

        if ($fechaDesde) {
            $sub->whereDate('hu.created_at', '>=', $fechaDesde);
        }

        if ($hayTecnicas || $hayBlandas) {
            $sub->where(function ($w) use ($tecItems, $tecNiveles, $blaItems, $blaNiveles, $hayTecnicas, $hayBlandas) {
                if ($hayTecnicas) {
                    $w->orWhere(function ($q) use ($tecItems, $tecNiveles) {
                        $q->where('hb.tipo', 'tecnica');

                        if (!empty($tecItems)) {
                            $q->whereIn('hb.nombre_normalizado', $this->normalize($tecItems));
                        }

                        $niveles = $this->normalize($tecNiveles);

                        if (!empty($niveles) && !in_array('todos', $niveles)) {
                            $q->whereIn(DB::raw('LOWER(hu.nivel)'), $niveles);
                        }
                    });
                }

                if ($hayBlandas) {
                    $w->orWhere(function ($q) use ($blaItems, $blaNiveles) {
                        $q->where('hb.tipo', 'blanda');

                        if (!empty($blaItems)) {
                            $q->whereIn('hb.nombre_normalizado', $this->normalize($blaItems));
                        }

                        $niveles = $this->normalize($blaNiveles);

                        if (!empty($niveles) && !in_array('todos', $niveles)) {
                            $q->whereIn(DB::raw('LOWER(hu.nivel)'), $niveles);
                        }
                    });
                }
            });
        }

        return $sub->groupBy('hu.usuario_id');
    }

    /** Construye subconsulta de experiencias filtradas */
    private function subExperiencias(array $f)
    {
        $experiencias = data_get($f, 'experiencia', []);
        $fechaDesde = $this->fechaDesde($f);

        $sub = DB::table('experiencias as ex')
            ->select('ex.usuario_id', DB::raw('COUNT(*) as total'))
            ->whereRaw('ex.es_publico IS TRUE');

        if ($fechaDesde) {
            $sub->whereDate('ex.fecha_inicio', '>=', $fechaDesde);
        }

        if (!empty($experiencias)) {
            $sub->where(function ($w) use ($experiencias) {
                foreach ($experiencias as $item) {
                    $cargo = data_get($item, 'cargo');
                    $tipos = data_get($item, 'tipos', []);

                    if (empty($cargo) && empty($tipos)) {
                        continue;
                    }

                    $w->orWhere(function ($q) use ($cargo, $tipos) {
                        if (!empty($cargo)) {
                            $q->where('ex.cargo', 'ilike', "%{$cargo}%");
                        }

                        $tiposLower = $this->normalize($tipos);

                        if (!empty($tiposLower) && !in_array('ambos', $tiposLower)) {
                            $q->whereIn(DB::raw('LOWER(ex.tipo)'), $tiposLower);
                        }
                    });
                }
            });
        }

        return $sub->groupBy('ex.usuario_id');
    }

    /** Construye subconsulta de proyectos filtrados */
    private function subProyectos(array $f)
    {
        $pr = data_get($f, 'proyectos', []);
        $tecnologias = data_get($pr, 'tecnologias', []);
        $tipo = data_get($pr, 'tipo', []);
        $estado = data_get($pr, 'estado', []);
        $fechaDesde = $this->fechaDesde($f);

        $sub = DB::table('participaciones as par')
            ->join('proyectos as p', 'p.id_proyecto', '=', 'par.id_proyecto')
            ->select('par.id_usuario as usuario_id', DB::raw('COUNT(*) as total'))
            ->where('par.visibilidad', 'publico')
            ->whereNull('par.deleted_at')
            ->whereNull('p.deleted_at');

        if ($fechaDesde) {
            $sub->whereDate('par.fecha_inicio', '>=', $fechaDesde);
        }

        if (!empty($tecnologias)) {
            $sub->whereExists(function ($q) use ($tecnologias) {
                $q->from('uso_tecnologias as ut')
                    ->join('tecnologias as t', 't.id_tecnologia', '=', 'ut.id_tecnologia')
                    ->whereColumn('ut.id_proyecto', 'p.id_proyecto')
                    ->whereNull('ut.deleted_at')
                    ->whereRaw('ut.es_visible IS TRUE')
                    ->whereIn(DB::raw('LOWER(t.nombre)'), $this->normalize($tecnologias));
            });
        }

        if (!empty($tipo)) {
            $sub->whereIn(
                DB::raw('LOWER(p.categoria_proyecto)'),
                $this->normalizeEnum($tipo)
            );
        }

        if (!empty($estado)) {
            $estadoLower = $this->normalizeEnum($estado);

            if (!in_array('todos', $estadoLower)) {
                $sub->where(function ($q) use ($estadoLower) {
                    if (in_array('publicado', $estadoLower)) {
                        $q->orWhere('p.estado_publicacion', 'publicado');
                    }

                    if (in_array('borrador', $estadoLower)) {
                        $q->orWhere('p.estado_publicacion', 'borrador');
                    }

                    if (in_array('archivado', $estadoLower)) {
                        $q->orWhere('p.estado_publicacion', 'archivado');
                    }

                    if (in_array('en_desarrollo', $estadoLower)) {
                        $q->orWhere('p.estado_desarrollo', 'en_desarrollo');
                    }
                });
            }
        }

        return $sub->groupBy('par.id_usuario');
    }

    /** Construye subconsulta de habilidades sin filtro */
    private function subHabilidadesSinFiltro(array $ids, array $f)
    {
        $fechaDesde = $this->fechaDesde($f);

        $habilidades = DB::table('habilidades_usuario as hu')
            ->join('habilidades as hb', 'hb.id_habilidad', '=', 'hu.habilidad_id')
            ->select(
                'hu.usuario_id',
                DB::raw('hb.nombre as nombre'),
                DB::raw('LOWER(hb.nombre_normalizado) as clave')
            )
            ->whereRaw('hu.es_visible IS TRUE')
            ->whereRaw('hb.estado IS TRUE')
            ->where('hb.tipo', 'tecnica')
            ->whereIn('hu.usuario_id', $ids);

        if ($fechaDesde) {
            $habilidades->whereDate('hu.created_at', '>=', $fechaDesde);
        }

        $tecnologiasProyecto = DB::table('participaciones as par')
            ->join('proyectos as p', 'p.id_proyecto', '=', 'par.id_proyecto')
            ->join('uso_tecnologias as ut', 'ut.id_proyecto', '=', 'p.id_proyecto')
            ->join('tecnologias as t', 't.id_tecnologia', '=', 'ut.id_tecnologia')
            ->select(
                'par.id_usuario as usuario_id',
                DB::raw('t.nombre as nombre'),
                DB::raw('LOWER(t.nombre) as clave')
            )
            ->where('par.visibilidad', 'publico')
            ->whereNull('par.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereNull('ut.deleted_at')
            ->whereRaw('ut.es_visible IS TRUE')
            ->whereIn('par.id_usuario', $ids);

        if ($fechaDesde) {
            $tecnologiasProyecto->whereDate('par.fecha_inicio', '>=', $fechaDesde);
        }

        $union = $habilidades->unionAll($tecnologiasProyecto);

        return DB::query()
            ->fromSub($union, 'x')
            ->select(
                'x.usuario_id',
                DB::raw('COUNT(DISTINCT x.clave) as total'),
                DB::raw("STRING_AGG(DISTINCT x.nombre, ', ') as tecnologias")
            )
            ->groupBy('x.usuario_id');
    }

    /** Construye subconsulta de habilidades para ordenar por tokens */
    private function subHabilidadesPorTokens(array $ids, array $f, array $tokens, ?string $tipo = null)
    {
        $q = DB::table('habilidades_usuario as hu')
            ->join('habilidades as hb', 'hb.id_habilidad', '=', 'hu.habilidad_id')
            ->select('hu.usuario_id', DB::raw('COUNT(DISTINCT hb.id_habilidad) as total'))
            ->whereRaw('hu.es_visible IS TRUE')
            ->whereRaw('hb.estado IS TRUE')
            ->whereIn('hu.usuario_id', $ids);

        if ($tipo) {
            $q->where('hb.tipo', $tipo);
        }

        $this->applyTokenWhere($q, ['hb.nombre', 'hb.nombre_normalizado'], $tokens);

        if ($fechaDesde = $this->fechaDesde($f)) {
            $q->whereDate('hu.created_at', '>=', $fechaDesde);
        }

        return $q->groupBy('hu.usuario_id');
    }

    /** Construye subconsulta de experiencias sin filtro */
    private function subExperienciasSinFiltro(array $ids, array $f)
    {
        $fechaDesde = $this->fechaDesde($f);

        $q = DB::table('experiencias as ex')
            ->select('ex.usuario_id', DB::raw('COUNT(*) as total'))
            ->whereRaw('ex.es_publico IS TRUE')
            ->whereIn('ex.usuario_id', $ids);

        if ($fechaDesde) {
            $q->whereDate('ex.fecha_inicio', '>=', $fechaDesde);
        }

        return $q->groupBy('ex.usuario_id');
    }

    /** Construye subconsulta de experiencias para ordenar por tokens */
    private function subExperienciasPorTokens(array $ids, array $f, array $tokens)
    {
        $q = DB::table('experiencias as ex')
            ->select('ex.usuario_id', DB::raw('COUNT(DISTINCT ex.id_experiencia) as total'))
            ->whereRaw('ex.es_publico IS TRUE')
            ->whereIn('ex.usuario_id', $ids);

        $this->applyTokenWhere($q, ['ex.cargo'], $tokens);

        if ($fechaDesde = $this->fechaDesde($f)) {
            $q->whereDate('ex.fecha_inicio', '>=', $fechaDesde);
        }

        return $q->groupBy('ex.usuario_id');
    }

    /** Construye subconsulta de proyectos sin filtro */
    private function subProyectosSinFiltro(array $ids, array $f)
    {
        $fechaDesde = $this->fechaDesde($f);

        $q = DB::table('participaciones as par')
            ->join('proyectos as p', 'p.id_proyecto', '=', 'par.id_proyecto')
            ->select('par.id_usuario as usuario_id', DB::raw('COUNT(*) as total'))
            ->where('par.visibilidad', 'publico')
            ->whereNull('par.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereIn('par.id_usuario', $ids);

        if ($fechaDesde) {
            $q->whereDate('par.fecha_inicio', '>=', $fechaDesde);
        }

        return $q->groupBy('par.id_usuario');
    }

    /** Construye subconsulta de proyectos para ordenar por tecnologias */
    private function subProyectosPorTokens(array $ids, array $f, array $tokens)
    {
        $q = DB::table('participaciones as par')
            ->join('proyectos as p', 'p.id_proyecto', '=', 'par.id_proyecto')
            ->select('par.id_usuario as usuario_id', DB::raw('COUNT(DISTINCT p.id_proyecto) as total'))
            ->where('par.visibilidad', 'publico')
            ->whereNull('par.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereIn('par.id_usuario', $ids)
            ->whereExists(function ($s) use ($tokens) {
                $s->from('uso_tecnologias as ut')
                    ->join('tecnologias as t', 't.id_tecnologia', '=', 'ut.id_tecnologia')
                    ->whereColumn('ut.id_proyecto', 'p.id_proyecto')
                    ->whereNull('ut.deleted_at')
                    ->whereRaw('ut.es_visible IS TRUE');

                $this->applyTokenWhere($s, ['t.nombre'], $tokens);
            });

        if ($fechaDesde = $this->fechaDesde($f)) {
            $q->whereDate('par.fecha_inicio', '>=', $fechaDesde);
        }

        return $q->groupBy('par.id_usuario');
    }

    /** Une los elementos relacionados visibles */
    private function applyRelatedItemsSubquery($q, array $idsFinalistas, array $f): void
    {
        $this->leftJoinSubquery($q, $this->subItemsRelacionados($idsFinalistas, $f), 'r');
    }

    /** Construye subconsulta de elementos relacionados */
    private function subItemsRelacionados(array $ids, array $f)
    {
        $fechaDesde = $this->fechaDesde($f);
        $tokens = $this->tokensRelacionados($f);
        $union = $this->relatedSkillsQuery($ids, $fechaDesde, $tokens);

        if (!empty($tokens)) {
            $union->unionAll($this->relatedProjectTechQuery($ids, $fechaDesde, $tokens));
            $union->unionAll($this->relatedExperienceQuery($ids, $fechaDesde, $tokens));
        }

        $agrupado = DB::query()
            ->fromSub($union, 'x')
            ->select(
                'x.usuario_id',
                'x.clave',
                DB::raw('MIN(x.nombre) as nombre'),
                DB::raw('MAX(x.match_score) as match_score'),
                DB::raw('MIN(x.source_order) as source_order')
            )
            ->groupBy('x.usuario_id', 'x.clave');

        return DB::query()
            ->fromSub($agrupado, 'r')
            ->select(
                'r.usuario_id',
                DB::raw('COUNT(*) as total'),
                DB::raw("STRING_AGG(r.nombre, ', ' ORDER BY r.match_score DESC, r.source_order ASC, r.nombre ASC) as tecnologias")
            )
            ->groupBy('r.usuario_id');
    }

    /** Construye fuente de habilidades relacionadas */
    private function relatedSkillsQuery(array $ids, ?string $fechaDesde, array $tokens)
    {
        $bindings = [];
        $matchScore = $this->relatedMatchScoreSql($tokens, ['hb.nombre', 'hb.nombre_normalizado'], $bindings);

        $q = DB::table('habilidades_usuario as hu')
            ->join('habilidades as hb', 'hb.id_habilidad', '=', 'hu.habilidad_id')
            ->select(
                'hu.usuario_id',
                DB::raw('hb.nombre as nombre'),
                DB::raw('LOWER(hb.nombre_normalizado) as clave'),
                DB::raw('1 as source_order')
            )
            ->selectRaw("{$matchScore} as match_score", $bindings)
            ->whereRaw('hu.es_visible IS TRUE')
            ->whereRaw('hb.estado IS TRUE')
            ->whereIn('hb.tipo', ['tecnica', 'blanda'])
            ->whereIn('hu.usuario_id', $ids);

        if ($fechaDesde) {
            $q->whereDate('hu.created_at', '>=', $fechaDesde);
        }

        return $q;
    }

    /** Construye fuente de tecnologias de proyectos relacionadas */
    private function relatedProjectTechQuery(array $ids, ?string $fechaDesde, array $tokens)
    {
        $bindings = [];
        $matchScore = $this->relatedMatchScoreSql($tokens, ['t.nombre'], $bindings);

        $q = DB::table('participaciones as par')
            ->join('proyectos as p', 'p.id_proyecto', '=', 'par.id_proyecto')
            ->join('uso_tecnologias as ut', 'ut.id_proyecto', '=', 'p.id_proyecto')
            ->join('tecnologias as t', 't.id_tecnologia', '=', 'ut.id_tecnologia')
            ->select(
                'par.id_usuario as usuario_id',
                DB::raw('t.nombre as nombre'),
                DB::raw('LOWER(t.nombre) as clave'),
                DB::raw('2 as source_order')
            )
            ->selectRaw("{$matchScore} as match_score", $bindings)
            ->where('par.visibilidad', 'publico')
            ->whereNull('par.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereNull('ut.deleted_at')
            ->whereRaw('ut.es_visible IS TRUE')
            ->whereIn('par.id_usuario', $ids);

        $this->applyRelatedTokenWhere($q, ['t.nombre'], $tokens);

        if ($fechaDesde) {
            $q->whereDate('par.fecha_inicio', '>=', $fechaDesde);
        }

        return $q;
    }

    /** Construye fuente de experiencias relacionadas */
    private function relatedExperienceQuery(array $ids, ?string $fechaDesde, array $tokens)
    {
        $bindings = [];
        $matchScore = $this->relatedMatchScoreSql($tokens, ['ex.cargo'], $bindings);

        $q = DB::table('experiencias as ex')
            ->select(
                'ex.usuario_id',
                DB::raw('ex.cargo as nombre'),
                DB::raw('LOWER(ex.cargo) as clave'),
                DB::raw('3 as source_order')
            )
            ->selectRaw("{$matchScore} as match_score", $bindings)
            ->whereRaw('ex.es_publico IS TRUE')
            ->whereNotNull('ex.cargo')
            ->where('ex.cargo', '<>', '')
            ->whereIn('ex.usuario_id', $ids);

        $this->applyRelatedTokenWhere($q, ['ex.cargo'], $tokens);

        if ($fechaDesde) {
            $q->whereDate('ex.fecha_inicio', '>=', $fechaDesde);
        }

        return $q;
    }

    /** Aplica ordenamiento final */
    private function applyOrdering($q, array $f, array $filtros): void
    {
        $asc = $this->ordenAscendente($f);
        $dir = $asc ? 'asc' : 'desc';
        $idDir = $asc ? 'desc' : 'asc';

        if ($filtros['query']) {
            $q->orderByRaw("relevancia_textual {$dir}");
            $this->applyMetricOrdering($q, $f, $dir);
            $q->orderBy('usuarios.id_usuario', $idDir);
            return;
        }

        $this->applyMetricOrdering($q, $f, $dir);
        $q->orderBy('usuarios.id_usuario', $idDir);
    }

    /** Indica si el orden final debe invertirse */
    private function ordenAscendente(array $f): bool
    {
        return strtolower((string) data_get($f, 'orden.direccion', 'desc')) === 'asc';
    }

    /** Genera SQL para contar tokens coincidentes */
    private function queryMatchedTokensSql(array $f, array &$bindings): string
    {
        $tokens = $this->queryTokens((string) data_get($f, 'query', ''));
        $fechaDesde = $this->fechaDesde($f);

        if (empty($tokens)) {
            return '0';
        }

        $parts = [];

        foreach ($tokens as $token) {
            $like = "%{$token}%";

            $parts[] = "
                CASE WHEN (
                    LOWER(usuarios.nombre || ' ' || usuarios.apellido) LIKE ?
                    OR (
                        COALESCE(vis_profesion.visible, false) = true
                        AND perfiles.profesion ILIKE ?
                    )
                    OR (
                        COALESCE(vis_ciudad.visible, false) = true
                        AND perfiles.ciudad ILIKE ?
                    )
                    OR (
                        COALESCE(vis_pais.visible, false) = true
                        AND perfiles.pais ILIKE ?
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM habilidades_usuario hu
                        JOIN habilidades hb ON hb.id_habilidad = hu.habilidad_id
                        WHERE hu.usuario_id = usuarios.id_usuario
                        AND hu.es_visible IS TRUE
                        AND hb.estado IS TRUE
                        AND (
                            hb.nombre ILIKE ?
                            OR hb.nombre_normalizado ILIKE ?
                        )
                        " . ($fechaDesde ? " AND DATE(hu.created_at) >= ? " : "") . "
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM experiencias ex
                        WHERE ex.usuario_id = usuarios.id_usuario
                        AND ex.es_publico IS TRUE
                        AND ex.cargo ILIKE ?
                        " . ($fechaDesde ? " AND DATE(ex.fecha_inicio) >= ? " : "") . "
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM participaciones par
                        JOIN proyectos p2 ON p2.id_proyecto = par.id_proyecto
                        JOIN uso_tecnologias ut ON ut.id_proyecto = p2.id_proyecto
                        JOIN tecnologias t ON t.id_tecnologia = ut.id_tecnologia
                        WHERE par.id_usuario = usuarios.id_usuario
                        AND par.visibilidad = 'publico'
                        AND par.deleted_at IS NULL
                        AND p2.deleted_at IS NULL
                        AND ut.deleted_at IS NULL
                        AND ut.es_visible IS TRUE
                        AND t.nombre ILIKE ?
                        " . ($fechaDesde ? " AND DATE(par.fecha_inicio) >= ? " : "") . "
                    )
                ) THEN 1 ELSE 0 END
            ";

            $bindings[] = $like;
            $bindings[] = $like;
            $bindings[] = $like;
            $bindings[] = $like;
            $bindings[] = $like;
            $bindings[] = $like;

            if ($fechaDesde) {
                $bindings[] = $fechaDesde;
            }

            $bindings[] = $like;

            if ($fechaDesde) {
                $bindings[] = $fechaDesde;
            }

            $bindings[] = $like;

            if ($fechaDesde) {
                $bindings[] = $fechaDesde;
            }
        }

        return implode(' + ', $parts);
    }

    /** Ordena combinando coincidencias y conteos visibles */
    private function applyMetricOrdering($q, array $f, string $dir): void
    {
        foreach ($this->metricOrder($f) as $metric) {
            [$matchAlias, $visibleAlias] = $this->metricAliases($metric);

            $q->orderByRaw("COALESCE({$matchAlias}.total, 0) {$dir}");
            $q->orderByRaw("COALESCE({$visibleAlias}.total, 0) {$dir}");
        }
    }

    /** Obtiene orden de metricas */
    private function metricOrder(array $f): array
    {
        if (data_get($f, 'orden.priorizar_proyectos')) {
            return ['proyectos', 'habilidades', 'experiencia'];
        }

        if (data_get($f, 'orden.priorizar_habilidades')) {
            return ['habilidades', 'proyectos', 'experiencia'];
        }

        if (data_get($f, 'orden.priorizar_experiencia')) {
            return ['experiencia', 'proyectos', 'habilidades'];
        }

        return ['proyectos', 'habilidades', 'experiencia'];
    }

    /** Relaciona metricas de coincidencia con metricas visibles */
    private function metricAliases(string $metric): array
    {
        return match ($metric) {
            'habilidades' => ['oh', 'h'],
            'experiencia' => ['oe', 'e'],
            default => ['op', 'p'],
        };
    }

    /** Convierte el texto en tokens */
    private function queryTokens(string $query): array
    {
        $query = Str::lower(Str::ascii(trim($query)));
        $tokens = preg_split('/\s+/', $query);

        return array_values(array_unique(array_filter($tokens, function ($token) {
            return trim($token) !== '';
        })));
    }

    /** Obtiene tokens para ordenar elementos relacionados */
    private function tokensRelacionados(array $f): array
    {
        $tokens = [];
        $query = trim((string) data_get($f, 'query', ''));

        if ($query !== '') {
            $tokens = array_merge($tokens, $this->queryTokens($query));
        }

        $tokens = array_merge(
            $tokens,
            $this->normalize((array) data_get($f, 'habilidades.tecnicas.items', [])),
            $this->normalize((array) data_get($f, 'habilidades.blandas.items', [])),
            $this->normalize((array) data_get($f, 'proyectos.tecnologias', [])),
            $this->experienceCargoTokens($f)
        );

        return array_values(array_unique(array_filter($tokens)));
    }

    /** Obtiene tokens de cargos de experiencia */
    private function experienceCargoTokens(array $f): array
    {
        $tokens = [];

        foreach ((array) data_get($f, 'experiencia', []) as $item) {
            $cargo = trim((string) data_get($item, 'cargo', ''));

            if ($cargo !== '') {
                $tokens = array_merge($tokens, $this->queryTokens($cargo));
            }
        }

        return $tokens;
    }

    /** Genera puntaje de elementos relacionados */
    private function relatedMatchScoreSql(array $tokens, array $columns, array &$bindings): string
    {
        if (empty($tokens)) {
            return '0';
        }

        $exactParts = [];
        $partialParts = [];

        foreach ($tokens as $token) {
            foreach ($columns as $column) {
                $exactParts[] = "LOWER({$column}) = ?";
                $bindings[] = $token;
            }
        }

        foreach ($tokens as $token) {
            foreach ($columns as $column) {
                $partialParts[] = "{$column} ILIKE ?";
                $bindings[] = "%{$token}%";
            }
        }

        return "CASE WHEN " . implode(' OR ', $exactParts) . " THEN 2 WHEN " . implode(' OR ', $partialParts) . " THEN 1 ELSE 0 END";
    }

    /** Aplica coincidencias por tokens */
    private function applyTokenWhere($q, array $columns, array $tokens): void
    {
        $q->where(function ($w) use ($columns, $tokens) {
            foreach ($tokens as $token) {
                foreach ($columns as $column) {
                    $w->orWhere($column, 'ilike', "%{$token}%");
                }
            }
        });
    }

    /** Detecta tokens existentes en habilidades */
    private function tokensEnHabilidades(array $tokens, ?string $tipo = null): array
    {
        return $this->tokensConMatch($tokens, function ($token) use ($tipo) {
            $q = DB::table('habilidades as hb')
                ->whereRaw('hb.estado IS TRUE')
                ->where(function ($w) use ($token) {
                    $w->where('hb.nombre', 'ilike', "%{$token}%")
                        ->orWhere('hb.nombre_normalizado', 'ilike', "%{$token}%");
                });

            if ($tipo) {
                $q->where('hb.tipo', $tipo);
            }

            return $q->exists();
        });
    }

    /** Detecta tokens existentes en experiencias */
    private function tokensEnExperiencias(array $tokens): array
    {
        return $this->tokensConMatch($tokens, function ($token) {
            return DB::table('experiencias as ex')
                ->whereRaw('ex.es_publico IS TRUE')
                ->where('ex.cargo', 'ilike', "%{$token}%")
                ->exists();
        });
    }

    /** Detecta tokens existentes en tecnologias de proyectos */
    private function tokensEnTecnologiasProyecto(array $tokens): array
    {
        return $this->tokensConMatch($tokens, function ($token) {
            return DB::table('tecnologias as t')
                ->join('uso_tecnologias as ut', 'ut.id_tecnologia', '=', 't.id_tecnologia')
                ->join('proyectos as p', 'p.id_proyecto', '=', 'ut.id_proyecto')
                ->join('participaciones as par', 'par.id_proyecto', '=', 'p.id_proyecto')
                ->whereNull('ut.deleted_at')
                ->whereNull('p.deleted_at')
                ->whereNull('par.deleted_at')
                ->whereRaw('ut.es_visible IS TRUE')
                ->where('par.visibilidad', 'publico')
                ->where('t.nombre', 'ilike', "%{$token}%")
                ->exists();
        });
    }

    /** Filtra tokens con coincidencia real */
    private function tokensConMatch(array $tokens, callable $callback): array
    {
        return array_values(array_filter($tokens, fn($token) => $callback($token)));
    }

    /** Aplica coincidencias por tokens */
    private function applyRelatedTokenWhere($q, array $columns, array $tokens): void
    {
        $q->where(function ($w) use ($columns, $tokens) {
            foreach ($tokens as $token) {
                foreach ($columns as $column) {
                    $w->orWhere($column, 'ilike', "%{$token}%");
                }
            }
        });
    }

    /** Agrega cantidad de palabras coincidentes */
    private function addQueryRelevanceSelect($q, array $f): void
    {
        $bindings = [];
        $sql = $this->queryMatchedTokensSql($f, $bindings);

        $q->selectRaw("({$sql}) AS relevancia_textual", $bindings);
    }

    /** Limpia repetidos y vacios */
    private function unique(array $arr): array
    {
        return array_values(array_unique(array_filter($arr, fn($v) => trim((string) $v) !== '')));
    }

    /** Normaliza textos */
    private function normalize(array $arr): array
    {
        return array_values(array_filter(array_map(
            fn($v) => Str::lower(Str::ascii(trim((string) $v))),
            $arr
        )));
    }

    /** Normaliza enums */
    private function normalizeEnum(array $arr): array
    {
        return array_map(
            fn($v) => str_replace(' ', '_', $v),
            $this->normalize($arr)
        );
    }

    /** Aplica filtro de fecha desde */
    private function applyFechaDesdeFilter($q, array $f): void
    {
        $fechaDesde = data_get($f, 'orden.fecha_desde');

        if (!empty($fechaDesde)) {
            $q->whereDate('usuarios.created_at', '>=', $fechaDesde);
        }
    }

    /** Obtiene fecha desde */
    private function fechaDesde(array $f): ?string
    {
        $fechaDesde = data_get($f, 'orden.fecha_desde');

        return !empty($fechaDesde) ? $fechaDesde : null;
    }

    /** Lista profesiones visibles */
    public function getProfesiones()
    {
        return DB::table('perfiles')
            ->join('usuarios', 'usuarios.id_usuario', '=', 'perfiles.usuario_id')
            ->join('visibilidad_campos as vis_profesion', function ($join) {
                $join->on('vis_profesion.usuario_id', '=', 'usuarios.id_usuario')
                    ->whereRaw("vis_profesion.campo = 'profesion'");
            })
            ->select('perfiles.profesion')
            ->whereIn('usuarios.estado', ['activo', 'pausado'])
            ->whereRaw('perfiles.es_publico IS TRUE')
            ->whereRaw('vis_profesion.visible IS TRUE')
            ->whereNotNull('perfiles.profesion')
            ->where('perfiles.profesion', '<>', '')
            ->distinct()
            ->orderBy('perfiles.profesion')
            ->pluck('perfiles.profesion');
    }

    /** Lista habilidades blandas */
    public function getHabilidadesBlandas()
    {
        return Habilidad::query()
            ->whereRaw('estado IS TRUE')
            ->where('tipo', 'blanda')
            ->orderBy('nombre')
            ->pluck('nombre');
    }

    /** Lista habilidades tecnicas */
    public function getHabilidadesTecnicas()
    {
        return Habilidad::query()
            ->whereRaw('estado IS TRUE')
            ->where('tipo', 'tecnica')
            ->orderBy('nombre')
            ->pluck('nombre');
    }

    /** Lista cargos de experiencia */
    public function getCargosExperiencia()
    {
        return DB::table('experiencias as ex')
            ->whereRaw('ex.es_publico IS TRUE')
            ->whereNotNull('ex.cargo')
            ->where('ex.cargo', '<>', '')
            ->orderBy('ex.cargo')
            ->pluck('ex.cargo')
            ->map(fn ($cargo) => trim(preg_replace('/\s+/u', ' ', (string) $cargo)))
            ->filter()
            ->unique(fn ($cargo) => Str::lower(Str::ascii($cargo)))
            ->values();
    }

    /** Lista tecnologias de proyectos */
    public function getTecnologiasProyecto()
    {
        return DB::table('tecnologias as t')
            ->join('uso_tecnologias as ut', 'ut.id_tecnologia', '=', 't.id_tecnologia')
            ->join('proyectos as p', 'p.id_proyecto', '=', 'ut.id_proyecto')
            ->join('participaciones as par', 'par.id_proyecto', '=', 'p.id_proyecto')
            ->whereNull('ut.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereRaw('ut.es_visible IS TRUE')
            ->where('par.visibilidad', 'publico')
            ->whereNull('par.deleted_at')
            ->distinct()
            ->orderBy('t.nombre')
            ->pluck('t.nombre');
    }

    /** Lista tipos de proyecto */
    public function getTiposProyecto()
    {
        return DB::table('proyectos as p')
            ->join('participaciones as par', 'par.id_proyecto', '=', 'p.id_proyecto')
            ->whereNull('p.deleted_at')
            ->whereNull('par.deleted_at')
            ->where('par.visibilidad', 'publico')
            ->whereNotNull('p.categoria_proyecto')
            ->where('p.categoria_proyecto', '<>', '')
            ->distinct()
            ->orderBy('p.categoria_proyecto')
            ->pluck('p.categoria_proyecto');
    }
}
