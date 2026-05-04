<?php

namespace App\Services\Api;

use Illuminate\Support\Facades\DB;
use App\Models\Habilidad;

class BusquedaService
{

public function search(array $f, int $perPage = 12)
{
    $tieneQuery       = !empty(trim(data_get($f, 'query', '')));
    $tieneUsuario     = $this->tieneValores(data_get($f, 'usuario'));
    $tieneHabilidades = $this->tieneValores(data_get($f, 'habilidades'));
    $tieneExperiencia = $this->tieneValores(data_get($f, 'experiencia'));
    $tieneProyectos   = $this->tieneValores(data_get($f, 'proyectos'));

    $q = DB::table('usuarios')
        ->join('perfiles', 'perfiles.usuario_id', '=', 'usuarios.id_usuario')
        ->where('usuarios.estado', 'activo')
        ->whereRaw('perfiles.es_publico IS TRUE');

    // query y usuario filtran directo en el query base
    if ($tieneQuery) {
        $this->applyQueryFilter($q, $f);
    }

    if ($tieneUsuario) {
        $this->applyUsuarioFilter($q, $f);
    }

    //SUBQUERIES CON FILTRO (habilidades, experiencia, proyectos)
    if ($tieneHabilidades) {
        $subH = $this->subHabilidades($f);
        $q->leftJoinSub($subH, 'h', fn($j) => $j->on('h.usuario_id', '=', 'usuarios.id_usuario'));
        $q->whereRaw('COALESCE(h.total, 0) > 0');
    }

    if ($tieneExperiencia) {
        $subE = $this->subExperiencias($f);
        $q->leftJoinSub($subE, 'e', fn($j) => $j->on('e.usuario_id', '=', 'usuarios.id_usuario'));
        $q->whereRaw('COALESCE(e.total, 0) > 0');
    }

    if ($tieneProyectos) {
        $subP = $this->subProyectos($f);
        $q->leftJoinSub($subP, 'p', fn($j) => $j->on('p.usuario_id', '=', 'usuarios.id_usuario'));
        $q->whereRaw('COALESCE(p.total, 0) > 0');
    }

    // ── IDs FINALISTAS 
    $idsFinalistas = (clone $q)->pluck('usuarios.id_usuario')->toArray();

    if (empty($idsFinalistas)) {
        return $q->paginate($perPage);
    }

    // ── SUBQUERIES SIN FILTRO (solo finalistas)
    if (!$tieneHabilidades) {
        $q->leftJoinSub($this->subHabilidadesSinFiltro($idsFinalistas), 'h',
            fn($j) => $j->on('h.usuario_id', '=', 'usuarios.id_usuario'));
    }

    if (!$tieneExperiencia) {
        $q->leftJoinSub($this->subExperienciasSinFiltro($idsFinalistas), 'e',
            fn($j) => $j->on('e.usuario_id', '=', 'usuarios.id_usuario'));
    }

    if (!$tieneProyectos) {
        $q->leftJoinSub($this->subProyectosSinFiltro($idsFinalistas), 'p',
            fn($j) => $j->on('p.usuario_id', '=', 'usuarios.id_usuario'));
    }

    // ── SELECT + ORDER ────────────────────────────────────
    $q->select([
        'usuarios.id_usuario',
        'usuarios.nombre',
        'usuarios.apellido',
        'perfiles.profesion',
        'perfiles.ciudad',
        'perfiles.pais',
        'perfiles.foto_perfil',
        DB::raw('COALESCE(h.total, 0)         AS habilidades_relacionadas'),
        DB::raw("COALESCE(h.tecnologias, '')   AS tecnologias_relacionadas"),
        DB::raw('COALESCE(e.total, 0)          AS experiencias_relacionadas'),
        DB::raw('COALESCE(p.total, 0)          AS proyectos_relacionados'),
    ]);

    $this->applyOrdering($q, $f);

    return $q->paginate($perPage);
}

    private function tieneValores(?array $bloque): bool
    {
        if (empty($bloque)) return false;

        foreach ($bloque as $valor) {
            // Si es array, verifica que no esté vacío
            if (is_array($valor) && !empty($valor)) return true;

            // Si es string/bool/int con valor real
            if (!is_array($valor) && !is_null($valor) && $valor !== '' && $valor !== false) return true;
        }

        return false;
    }

    private function applyQueryFilter($q, array $f): void
    {
    $texto = '%' . trim(data_get($f, 'query')) . '%';

    $q->where(function ($w) use ($texto) {
        // Nombre o apellido
        $w->whereRaw("LOWER(usuarios.nombre || ' ' || usuarios.apellido) LIKE LOWER(?)", [$texto])
        // Profesión
        ->orWhere('perfiles.profesion', 'ilike', $texto)
        // Habilidades técnicas
        ->orWhereExists(fn($s) => $s
            ->from('habilidades_usuario as hu')
            ->join('habilidades as hb', 'hb.id_habilidad', '=', 'hu.habilidad_id')
            ->whereColumn('hu.usuario_id', 'usuarios.id_usuario')
            ->whereRaw('hu.es_visible IS TRUE')
            ->where('hb.nombre', 'ilike', $texto)
        )
        // Tecnologías en proyectos
        ->orWhereExists(fn($s) => $s
            ->from('participaciones as par')
            ->join('proyectos as p2', 'p2.id_proyecto', '=', 'par.id_proyecto')
            ->join('uso_tecnologias as ut', 'ut.id_proyecto', '=', 'p2.id_proyecto')
            ->join('tecnologias as t', 't.id_tecnologia', '=', 'ut.id_tecnologia')
            ->whereColumn('par.id_usuario', 'usuarios.id_usuario')
            ->whereNull('p2.deleted_at')
            ->whereNull('ut.deleted_at')
            ->where('t.nombre', 'ilike', $texto)
        );
    });
}

    /**
     * Subquery de usuario: filtra nombre, ciudad, país y profesión.
     * Devuelve usuario_id
     */
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
            $q->whereIn(DB::raw('LOWER(perfiles.ciudad)'), $this->normalize($u['ciudad']));
        }

        if (!empty($u['pais'])) {
            $q->whereIn(DB::raw('LOWER(perfiles.pais)'), $this->normalize($u['pais']));
        }

        if (!empty($u['profesion'])) {
            $q->where(function ($w) use ($u) {
                foreach ($u['profesion'] as $p) {
                    $w->orWhere('perfiles.profesion', 'ilike', "%{$p}%");
                }
            });
        }
    }

    /**
     * Subquery de habilidades CON filtro:
     * Filtra por nombre (técnicas/blandas) y nivel.
     * Devuelve: usuario_id | total | tecnologias (string separado por comas)
     */
    private function subHabilidades(array $f)
    {
        $h   = data_get($f, 'habilidades', []);
        $tec = data_get($h, 'tecnicas', []);
        $bla = data_get($h, 'blandas', []);
        $niv = data_get($h, 'niveles', []);

        $sub = DB::table('habilidades_usuario as hu')
            ->join('habilidades as hb', 'hb.id_habilidad', '=', 'hu.habilidad_id')
            ->select(
                'hu.usuario_id',
                DB::raw('COUNT(*) as total'),
                DB::raw("STRING_AGG(hb.nombre, ', ') as tecnologias")
            )
            ->whereRaw('hu.es_visible IS TRUE')
            ->whereRaw('hb.estado IS TRUE');

        // Filtrar por nombre de habilidad
        $todosNombres = array_merge($tec, $bla);
        if (!empty($todosNombres)) {
            $sub->whereIn('hb.nombre_normalizado', $this->normalize($todosNombres));
        }

        // Filtrar por nivel
        if (!empty($niv) && !in_array('todos', $this->normalize($niv))) {
            $sub->whereIn('hu.nivel', $this->normalize($niv));
        }

        return $sub->groupBy('hu.usuario_id');
    }

    /**
     * Subquery de experiencias CON filtro:
     * Filtra por tipo (laboral/académica) y cargo.
     * Devuelve: usuario_id | total
     */
    private function subExperiencias(array $f)
    {
        $e    = data_get($f, 'experiencia', []);
        $tipo = data_get($e, 'tipo', []);
        $cargo = data_get($e, 'cargo', []);

        $sub = DB::table('experiencias as ex')
            ->select('ex.usuario_id', DB::raw('COUNT(*) as total'))
            ->whereRaw('ex.es_publico IS TRUE');

        // 'ambos' o vacío = sin filtro de tipo
        $tiposLower = $this->normalize($tipo);
        if (!empty($tipo) && !in_array('ambos', $tiposLower)) {
            $sub->whereIn('ex.tipo', $tiposLower);
        }

        if (!empty($cargo)) {
            $sub->where(function ($q) use ($cargo) {
                foreach ($cargo as $c) {
                    $q->orWhere('ex.cargo', 'ilike', "%{$c}%");
                }
            });
        }

        return $sub->groupBy('ex.usuario_id');
    }

    /**
     * Subquery de proyectos CON filtro:
     * Filtra por tecnologías, tipo de proyecto y estado.
     * Devuelve: usuario_id | total
     */
    private function subProyectos(array $f)
    {
        $pr          = data_get($f, 'proyectos', []);
        $tecnologias = data_get($pr, 'tecnologias', []);
        $tipo        = data_get($pr, 'tipo', []);
        $estado      = data_get($pr, 'estado', []);

        $sub = DB::table('participaciones as par')
            ->join('proyectos as p', 'p.id_proyecto', '=', 'par.id_proyecto')
            ->select('par.id_usuario as usuario_id', DB::raw('COUNT(*) as total'))
            ->where('par.visibilidad', 'publico')
            ->whereNull('p.deleted_at');

        if (!empty($tecnologias)) {
            $sub->whereExists(function ($q) use ($tecnologias) {
                $q->from('uso_tecnologias as ut')
                    ->join('tecnologias as t', 't.id_tecnologia', '=', 'ut.id_tecnologia')
                    ->whereColumn('ut.id_proyecto', 'p.id_proyecto')
                    ->whereNull('ut.deleted_at')
                    ->whereIn(DB::raw('LOWER(t.nombre)'), $this->normalize($tecnologias));
            });
        }

        if (!empty($tipo)) {
            $sub->join('tipos_proyecto as tp', 'tp.id_tipo_proyecto', '=', 'p.id_tipo_proyecto')
                ->whereIn(DB::raw('LOWER(tp.nombre)'), $this->normalize($tipo));
        }

        if (!empty($estado)) {
            $estadoLower = $this->normalize($estado);
            if (!in_array('todos', $estadoLower)) {
                $sub->where(function ($q) use ($estadoLower) {
                    if (in_array('publicado', $estadoLower)) {
                        $q->orWhere('p.estado_publicacion', 'publicado');
                    }
                    if (in_array('en_desarrollo', $estadoLower) || in_array('en desarrollo', $estadoLower)) {
                        $q->orWhere('p.estado_desarrollo', 'en_desarrollo');
                    }
                });
            }
        }

        return $sub->groupBy('par.id_usuario');
    }

    // Mismas columnas que las con filtro, pero sin WHERE
    // Se aplican solo a los finalistas del join

    /**
     * Habilidades técnicas SIN filtro:
     * Cuenta todas las habilidades técnicas visibles.
     * Devuelve: usuario_id | total | tecnologias (string)
     * (mismas columnas que subHabilidades)
     */
    private function subHabilidadesSinFiltro(array $ids)
    {
        return DB::table('habilidades_usuario as hu')
            ->join('habilidades as hb', 'hb.id_habilidad', '=', 'hu.habilidad_id')
            ->select(
                'hu.usuario_id',
                DB::raw('COUNT(*) as total'),
                DB::raw("STRING_AGG(hb.nombre, ', ') as tecnologias")
            )
            ->whereRaw('hu.es_visible IS TRUE')
            ->whereRaw('hb.estado IS TRUE')
            ->where('hb.tipo', 'tecnica')
            ->whereIn('hu.usuario_id', $ids)   // solo finalistas
            ->groupBy('hu.usuario_id');
    }

    /**
     * Experiencias SIN filtro:
     * Cuenta todas las experiencias públicas.
     * Devuelve: usuario_id | total
     * (misma columna que subExperiencias)
     */
    private function subExperienciasSinFiltro(array $ids)
    {
        return DB::table('experiencias as ex')
            ->select('ex.usuario_id', DB::raw('COUNT(*) as total'))
            ->whereRaw('ex.es_publico IS TRUE')
            ->whereIn('ex.usuario_id', $ids)   // solo finalistas
            ->groupBy('ex.usuario_id');
    }

    /**
     * Proyectos SIN filtro:
     * Cuenta todos los proyectos públicos del usuario.
     * Devuelve: usuario_id | total
     * (misma columna que subProyectos)
     */
    private function subProyectosSinFiltro(array $ids)
    {
        return DB::table('participaciones as par')
            ->join('proyectos as p', 'p.id_proyecto', '=', 'par.id_proyecto')
            ->select('par.id_usuario as usuario_id', DB::raw('COUNT(*) as total'))
            ->where('par.visibilidad', 'publico')
            ->whereNull('p.deleted_at')
            ->whereIn('par.id_usuario', $ids)  // solo finalistas
            ->groupBy('par.id_usuario');
    }

    // Ordenarmiento final

    private function applyOrdering($q, array $f): void
    {
        $o   = data_get($f, 'orden', []);
        $dir = strtolower(data_get($o, 'direccion', 'desc')) === 'asc' ? 'asc' : 'desc';

        $priorizarProyectos   = (bool) data_get($o, 'priorizar_proyectos', false);
        $priorizarExperiencia = (bool) data_get($o, 'priorizar_experiencia', false);
        $priorizarHabilidades = (bool) data_get($o, 'priorizar_habilidades', false);

        $fechaDesde = data_get($o, 'fecha_desde');
        if ($fechaDesde) {
            $q->where('perfiles.created_at', '>=', $fechaDesde);
        }

        // Priorización (solo una activa)
        if ($priorizarProyectos) {
            $q->orderByRaw("COALESCE(p.total, 0) {$dir}");
        } elseif ($priorizarExperiencia) {
            $q->orderByRaw("COALESCE(e.total, 0) {$dir}");
        } elseif ($priorizarHabilidades) {
            $q->orderByRaw("COALESCE(h.total, 0) {$dir}");
        }

        $campo = data_get($o, 'campo', 'relevancia');

        if ($campo === 'fecha') {
            $q->orderBy('perfiles.created_at', $dir);
        } else {
            // Relevancia: suma ponderada
            $q->orderByRaw("
                (
                    COALESCE(h.total, 0) * 3 +
                    COALESCE(e.total, 0) * 2 +
                    COALESCE(p.total, 0) * 1
                ) {$dir}
            ");
        }

        $q->orderBy('usuarios.id_usuario', 'asc');
    }

    // funcion auxiliar para normalizar arrays de filtros

    private function normalize(array $arr): array
    {
        return array_map(fn($v) => strtolower(trim($v)), $arr);
    }



    public function getProfesiones()
    {
        return DB::table('perfiles')
            ->join('usuarios', 'usuarios.id_usuario', '=', 'perfiles.usuario_id')
            ->select('perfiles.profesion')
            ->where('usuarios.estado', 'activo')
            ->whereRaw('perfiles.es_publico IS TRUE')
            ->whereNotNull('perfiles.profesion')
            ->where('perfiles.profesion', '<>', '')
            ->distinct()
            ->orderBy('perfiles.profesion')
            ->pluck('perfiles.profesion');
    }

    public function getHabilidadesBlandas()
    {
        return Habilidad::query()
            ->whereRaw('estado IS TRUE')
            ->where('tipo', 'blanda')
            ->orderBy('nombre')
            ->pluck('nombre');
    }

    public function getHabilidadesTecnicas()
    {
        return Habilidad::query()
            ->whereRaw('estado IS TRUE')
            ->where('tipo', 'tecnica')
            ->orderBy('nombre')
            ->pluck('nombre');
    }

    public function getCargosExperiencia()
    {
        return DB::table('experiencias as ex')
            ->select('ex.cargo')
            ->whereRaw('ex.es_publico IS TRUE')
            ->whereNotNull('ex.cargo')
            ->where('ex.cargo', '<>', '')
            ->distinct()
            ->orderBy('ex.cargo')
            ->pluck('ex.cargo');
    }

    public function getTecnologiasProyecto()
    {
        return DB::table('tecnologias as t')
            ->join('uso_tecnologias as ut', 'ut.id_tecnologia', '=', 't.id_tecnologia')
            ->join('proyectos as p', 'p.id_proyecto', '=', 'ut.id_proyecto')
            ->join('participaciones as par', 'par.id_proyecto', '=', 'p.id_proyecto')
            ->select('t.id_tecnologia', 't.nombre')
            ->whereNull('ut.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('par.visibilidad', 'publico')
            ->distinct()
            ->orderBy('t.nombre')
            ->get();
    }
}