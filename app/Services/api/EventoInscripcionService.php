<?php

namespace App\Services\api;

use App\Models\EventoInscripcion;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EventoInscripcionService
{
    private const ESTADO_INSCRITO = 'inscrito';
    private const ESTADO_DESINSCRITO = 'desinscrito';

    private const ESTADOS_EVENTO_VISIBLES = [
        'activo',
        'programado',
    ];

    /**
     * Muestra en el home solo los eventos que el usuario puede ver.
     * Usa la segmentación guardada por el creador del evento:
     * - target_mode
     * - segments
     * - target_selections
     */
    public function ver(int $usuarioId, int $perPage = 12): LengthAwarePaginator
    {
        $eventos = $this->baseEventosQuery($usuarioId)
            ->orderByRaw('CASE WHEN e.fecha_inicio IS NULL THEN 1 ELSE 0 END')
            ->orderBy('e.fecha_inicio')
            ->orderByDesc('e.created_at')
            ->get()
            ->filter(fn ($evento) => $this->usuarioPuedeVerEvento($usuarioId, $evento))
            ->map(fn ($evento) => $this->formatearEvento($evento))
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();

        $items = $eventos
            ->slice(($page - 1) * $perPage, $perPage)
            ->values();

        return new LengthAwarePaginator(
            $items,
            $eventos->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }

    /**
     * Inscribe al usuario en un evento
     */
    public function inscribirse(int $usuarioId, int $eventoId): array
    {
        return DB::transaction(function () use ($usuarioId, $eventoId) {
            $evento = DB::table('admin_eventos')
                ->where('id_evento', $eventoId)
                ->lockForUpdate()
                ->first();

            if (!$evento) {
                return $this->sinAccion('Evento no encontrado');
            }

            if (!$this->eventoEstaDisponible($evento)) {
                return $this->sinAccion('El evento no está disponible para inscripción');
            }

            if (!$this->usuarioPuedeVerEvento($usuarioId, $evento)) {
                return $this->sinAccion('El usuario no cumple la segmentación del evento');
            }

            $inscripcionActiva = EventoInscripcion::where('evento_id', $eventoId)
                ->where('usuario_id', $usuarioId)
                ->where('estado', self::ESTADO_INSCRITO)
                ->whereNull('fecha_desinscripcion')
                ->lockForUpdate()
                ->first();

            if ($inscripcionActiva) {
                return [
                    'accion' => false,
                    'mensaje' => 'El usuario ya está inscrito en este evento',
                    'datos' => [
                        'id_inscripcion' => $inscripcionActiva->id_inscripcion,
                        'evento_id' => $eventoId,
                        'usuario_id' => $usuarioId,
                        'estado' => $inscripcionActiva->estado,
                    ],
                ];
            }

            if ((int) $evento->cupo > 0 && (int) $evento->inscritos >= (int) $evento->cupo) {
                return $this->sinAccion('El evento ya no tiene cupos disponibles');
            }

            $inscripcion = EventoInscripcion::create([
                'evento_id' => $eventoId,
                'usuario_id' => $usuarioId,
                'estado' => self::ESTADO_INSCRITO,
                'fecha_inscripcion' => now(),
                'fecha_desinscripcion' => null,
            ]);

            DB::table('admin_eventos')
                ->where('id_evento', $eventoId)
                ->update([
                    'inscritos' => DB::raw('inscritos + 1'),
                    'updated_at' => now(),
                ]);

            return [
                'accion' => true,
                'mensaje' => 'Usuario inscrito correctamente',
                'datos' => [
                    'id_inscripcion' => $inscripcion->id_inscripcion,
                    'evento_id' => $eventoId,
                    'usuario_id' => $usuarioId,
                    'estado' => $inscripcion->estado,
                    'fecha_inscripcion' => $inscripcion->fecha_inscripcion,
                ],
            ];
        });
    }

    /**
     * Desinscribe al usuario de un evento
     */
    public function desinscribirse(int $usuarioId, int $eventoId): array
    {
        return DB::transaction(function () use ($usuarioId, $eventoId) {
            $inscripcion = EventoInscripcion::where('evento_id', $eventoId)
                ->where('usuario_id', $usuarioId)
                ->where('estado', self::ESTADO_INSCRITO)
                ->whereNull('fecha_desinscripcion')
                ->lockForUpdate()
                ->first();

            if (!$inscripcion) {
                return $this->sinAccion('El usuario no tiene una inscripción activa en este evento');
            }

            $inscripcion->update([
                'estado' => self::ESTADO_DESINSCRITO,
                'fecha_desinscripcion' => now(),
            ]);

            DB::table('admin_eventos')
                ->where('id_evento', $eventoId)
                ->update([
                    'inscritos' => DB::raw('GREATEST(inscritos - 1, 0)'),
                    'updated_at' => now(),
                ]);

            return [
                'accion' => true,
                'mensaje' => 'Usuario desinscrito correctamente',
                'datos' => [
                    'id_inscripcion' => $inscripcion->id_inscripcion,
                    'evento_id' => $eventoId,
                    'usuario_id' => $usuarioId,
                    'estado' => self::ESTADO_DESINSCRITO,
                    'fecha_desinscripcion' => $inscripcion->fecha_desinscripcion,
                ],
            ];
        });
    }

    private function baseEventosQuery(int $usuarioId)
    {
        $select = [
            'e.id_evento',
            'e.usuario_creador_id',
            'e.titulo',
            'e.descripcion',
            'e.tipo',
            'e.estado',
            'e.fecha_inicio',
            'e.fecha_fin',
            'e.programado_para',
            'e.ubicacion',
            'e.cupo',
            'e.inscritos',
            'e.interesados',
            'e.espera',
            'e.asistieron',
            'e.no_asistieron',
            'e.target_mode',
            'e.channels',
            'e.segments',
            'e.target_selections',
            'e.created_at',
            'e.updated_at',
            DB::raw('CASE WHEN ei.id_inscripcion IS NULL THEN false ELSE true END AS esta_inscrito'),
            'ei.id_inscripcion',
            'ei.fecha_inscripcion',
            'ei.fecha_desinscripcion',
        ];

        if (Schema::hasColumn('admin_eventos', 'imagen_portada_path')) {
            $select[] = 'e.imagen_portada_path';
        }

        if (Schema::hasColumn('admin_eventos', 'imagen_portada_url')) {
            $select[] = 'e.imagen_portada_url';
        }

        return DB::table('admin_eventos as e')
            ->leftJoin('evento_inscripciones as ei', function ($join) use ($usuarioId) {
                $join->on('ei.evento_id', '=', 'e.id_evento')
                    ->where('ei.usuario_id', '=', $usuarioId)
                    ->where('ei.estado', '=', self::ESTADO_INSCRITO)
                    ->whereNull('ei.fecha_desinscripcion');
            })
            ->whereIn('e.estado', self::ESTADOS_EVENTO_VISIBLES)
            ->where(function ($q) {
                $q->whereNull('e.fecha_fin')
                    ->orWhere('e.fecha_fin', '>=', now());
            })
            ->select($select);
    }

    private function eventoEstaDisponible(object $evento): bool
    {
        if (!in_array($evento->estado, self::ESTADOS_EVENTO_VISIBLES, true)) {
            return false;
        }

        if ($evento->fecha_fin && Carbon::parse($evento->fecha_fin)->lt(now())) {
            return false;
        }

        return true;
    }

    private function usuarioPuedeVerEvento(int $usuarioId, object $evento): bool
    {
        $targetMode = $evento->target_mode ?? 'all_users';

        if ($targetMode === 'all_users') {
            return true;
        }

        $targetSelections = $this->jsonToArray($evento->target_selections ?? null);
        $segments = $this->jsonToArray($evento->segments ?? null);

        $usuariosSeleccionados = $this->extraerIdsUsuarios($targetSelections);

        if (!empty($usuariosSeleccionados)) {
            return in_array($usuarioId, $usuariosSeleccionados, true);
        }

        if (!$this->tieneValores($segments)) {
            return true;
        }

        return $this->usuarioCumpleSegmentacion($usuarioId, $segments);
    }

    private function usuarioCumpleSegmentacion(int $usuarioId, array $segments): bool
    {
        if (!$this->cumpleQuery($usuarioId, $segments)) {
            return false;
        }

        if (!$this->cumpleUsuario($usuarioId, $segments)) {
            return false;
        }

        if (!$this->cumpleHabilidades($usuarioId, $segments)) {
            return false;
        }

        if (!$this->cumpleExperiencia($usuarioId, $segments)) {
            return false;
        }

        if (!$this->cumpleProyectos($usuarioId, $segments)) {
            return false;
        }

        return true;
    }

    private function cumpleQuery(int $usuarioId, array $segments): bool
    {
        $query = trim((string) data_get($segments, 'query', ''));

        if ($query === '') {
            return true;
        }

        $tokens = $this->queryTokens($query);

        if (empty($tokens)) {
            return true;
        }

        $usuario = $this->datosUsuario($usuarioId);

        if (!$usuario) {
            return false;
        }

        foreach ($tokens as $token) {
            $like = "%{$token}%";

            $coincideUsuario =
                str_contains($this->normalizarTexto($usuario->nombre . ' ' . $usuario->apellido), $token)
                || ($usuario->profesion_visible && str_contains($this->normalizarTexto($usuario->profesion), $token))
                || ($usuario->ciudad_visible && str_contains($this->normalizarTexto($usuario->ciudad), $token))
                || ($usuario->pais_visible && str_contains($this->normalizarTexto($usuario->pais), $token));

            if ($coincideUsuario) {
                return true;
            }

            if ($this->existeHabilidadPorTexto($usuarioId, $like)) {
                return true;
            }

            if ($this->existeExperienciaPorTexto($usuarioId, $like)) {
                return true;
            }

            if ($this->existeProyectoPorTexto($usuarioId, $like)) {
                return true;
            }
        }

        return false;
    }

    private function cumpleUsuario(int $usuarioId, array $segments): bool
    {
        $filtroUsuario = data_get($segments, 'usuario', []);

        if (!$this->tieneValores($filtroUsuario)) {
            return true;
        }

        $usuario = $this->datosUsuario($usuarioId);

        if (!$usuario) {
            return false;
        }

        $nombre = trim((string) data_get($filtroUsuario, 'nombre', ''));

        if ($nombre !== '') {
            $nombreCompleto = $this->normalizarTexto($usuario->nombre . ' ' . $usuario->apellido);

            if (!str_contains($nombreCompleto, $this->normalizarTexto($nombre))) {
                return false;
            }
        }

        $ciudades = $this->normalizarLista(data_get($filtroUsuario, 'ciudad', []));

        if (!empty($ciudades)) {
            if (!$usuario->ciudad_visible) {
                return false;
            }

            if (!in_array($this->normalizarTexto($usuario->ciudad), $ciudades, true)) {
                return false;
            }
        }

        $paises = $this->normalizarLista(data_get($filtroUsuario, 'pais', []));

        if (!empty($paises)) {
            if (!$usuario->pais_visible) {
                return false;
            }

            if (!in_array($this->normalizarTexto($usuario->pais), $paises, true)) {
                return false;
            }
        }

        $profesiones = $this->normalizarLista(data_get($filtroUsuario, 'profesion', []));

        if (!empty($profesiones)) {
            if (!$usuario->profesion_visible) {
                return false;
            }

            $profesionUsuario = $this->normalizarTexto($usuario->profesion);

            $cumple = collect($profesiones)
                ->contains(fn ($profesion) => str_contains($profesionUsuario, $profesion));

            if (!$cumple) {
                return false;
            }
        }

        $roles = $this->normalizarLista(data_get($filtroUsuario, 'rol', []));

        if (!empty($roles) && !in_array($this->normalizarTexto($usuario->rol), $roles, true)) {
            return false;
        }

        return true;
    }

    private function cumpleHabilidades(int $usuarioId, array $segments): bool
    {
        $habilidades = data_get($segments, 'habilidades', []);

        if (!$this->tieneValores($habilidades)) {
            return true;
        }

        $tecItems = $this->normalizarLista(data_get($habilidades, 'tecnicas.items', []));
        $tecNiveles = $this->normalizarLista(data_get($habilidades, 'tecnicas.niveles', []));
        $blaItems = $this->normalizarLista(data_get($habilidades, 'blandas.items', []));
        $blaNiveles = $this->normalizarLista(data_get($habilidades, 'blandas.niveles', []));
        $itemsGenerales = $this->normalizarLista(data_get($habilidades, 'items', []));

        $hayTecnicas = !empty($tecItems) || !empty($tecNiveles);
        $hayBlandas = !empty($blaItems) || !empty($blaNiveles);
        $hayGenerales = !empty($itemsGenerales);

        $q = DB::table('habilidades_usuario as hu')
            ->join('habilidades as hb', 'hb.id_habilidad', '=', 'hu.habilidad_id')
            ->where('hu.usuario_id', $usuarioId)
            ->whereRaw('hu.es_visible IS TRUE')
            ->whereRaw('hb.estado IS TRUE');

        if ($hayTecnicas || $hayBlandas) {
            $q->where(function ($w) use ($hayTecnicas, $hayBlandas, $tecItems, $tecNiveles, $blaItems, $blaNiveles) {
                if ($hayTecnicas) {
                    $w->orWhere(function ($s) use ($tecItems, $tecNiveles) {
                        $s->where('hb.tipo', 'tecnica');

                        if (!empty($tecItems)) {
                            $s->whereIn('hb.nombre_normalizado', $tecItems);
                        }

                        if (!empty($tecNiveles) && !in_array('todos', $tecNiveles, true)) {
                            $s->whereIn(DB::raw('LOWER(hu.nivel)'), $tecNiveles);
                        }
                    });
                }

                if ($hayBlandas) {
                    $w->orWhere(function ($s) use ($blaItems, $blaNiveles) {
                        $s->where('hb.tipo', 'blanda');

                        if (!empty($blaItems)) {
                            $s->whereIn('hb.nombre_normalizado', $blaItems);
                        }

                        if (!empty($blaNiveles) && !in_array('todos', $blaNiveles, true)) {
                            $s->whereIn(DB::raw('LOWER(hu.nivel)'), $blaNiveles);
                        }
                    });
                }
            });
        }

        if ($hayGenerales) {
            $q->whereIn('hb.nombre_normalizado', $itemsGenerales);
        }

        return $q->exists();
    }

    private function cumpleExperiencia(int $usuarioId, array $segments): bool
    {
        $experiencias = data_get($segments, 'experiencia', []);

        if (!$this->tieneValores($experiencias)) {
            return true;
        }

        $items = is_array($experiencias) ? $experiencias : [];

        $q = DB::table('experiencias as ex')
            ->where('ex.usuario_id', $usuarioId)
            ->whereRaw('ex.es_publico IS TRUE');

        $fechaDesde = $this->fechaDesde($segments);

        if ($fechaDesde) {
            $q->whereDate('ex.fecha_inicio', '>=', $fechaDesde);
        }

        $q->where(function ($w) use ($items) {
            foreach ($items as $item) {
                $cargo = trim((string) data_get($item, 'cargo', ''));
                $tipos = $this->normalizarLista(data_get($item, 'tipos', []));

                if ($cargo === '' && empty($tipos)) {
                    continue;
                }

                $w->orWhere(function ($s) use ($cargo, $tipos) {
                    if ($cargo !== '') {
                        $s->where('ex.cargo', 'ilike', "%{$cargo}%");
                    }

                    if (!empty($tipos) && !in_array('ambos', $tipos, true)) {
                        $s->whereIn(DB::raw('LOWER(ex.tipo)'), $tipos);
                    }
                });
            }
        });

        return $q->exists();
    }

    private function cumpleProyectos(int $usuarioId, array $segments): bool
    {
        $proyectos = data_get($segments, 'proyectos', []);

        if (!$this->tieneValores($proyectos)) {
            return true;
        }

        $tecnologias = $this->normalizarLista(data_get($proyectos, 'tecnologias', []));

        if (empty($tecnologias)) {
            return true;
        }

        $q = DB::table('participaciones as par')
            ->join('proyectos as p', 'p.id_proyecto', '=', 'par.id_proyecto')
            ->join('uso_tecnologias as ut', 'ut.id_proyecto', '=', 'p.id_proyecto')
            ->join('tecnologias as t', 't.id_tecnologia', '=', 'ut.id_tecnologia')
            ->where('par.id_usuario', $usuarioId)
            ->where('par.visibilidad', 'publico')
            ->whereNull('par.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereNull('ut.deleted_at')
            ->whereRaw('ut.es_visible IS TRUE')
            ->whereIn(DB::raw('LOWER(t.nombre)'), $tecnologias);

        $fechaDesde = $this->fechaDesde($segments);

        if ($fechaDesde) {
            $q->whereDate('par.fecha_inicio', '>=', $fechaDesde);
        }

        return $q->exists();
    }

    private function datosUsuario(int $usuarioId): ?object
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
            ->where('usuarios.id_usuario', $usuarioId)
            ->whereIn('usuarios.estado', ['activo', 'pausado'])
            ->whereRaw('perfiles.es_publico IS TRUE')
            ->select([
                'usuarios.id_usuario',
                'usuarios.nombre',
                'usuarios.apellido',
                'usuarios.rol',
                'usuarios.estado',
                'perfiles.profesion',
                'perfiles.ciudad',
                'perfiles.pais',
                DB::raw('COALESCE(vis_profesion.visible, false) AS profesion_visible'),
                DB::raw('COALESCE(vis_ciudad.visible, false) AS ciudad_visible'),
                DB::raw('COALESCE(vis_pais.visible, false) AS pais_visible'),
            ])
            ->first();
    }

    private function existeHabilidadPorTexto(int $usuarioId, string $like): bool
    {
        return DB::table('habilidades_usuario as hu')
            ->join('habilidades as hb', 'hb.id_habilidad', '=', 'hu.habilidad_id')
            ->where('hu.usuario_id', $usuarioId)
            ->whereRaw('hu.es_visible IS TRUE')
            ->whereRaw('hb.estado IS TRUE')
            ->where(function ($q) use ($like) {
                $q->where('hb.nombre', 'ilike', $like)
                    ->orWhere('hb.nombre_normalizado', 'ilike', $like);
            })
            ->exists();
    }

    private function existeExperienciaPorTexto(int $usuarioId, string $like): bool
    {
        return DB::table('experiencias as ex')
            ->where('ex.usuario_id', $usuarioId)
            ->whereRaw('ex.es_publico IS TRUE')
            ->where('ex.cargo', 'ilike', $like)
            ->exists();
    }

    private function existeProyectoPorTexto(int $usuarioId, string $like): bool
    {
        return DB::table('participaciones as par')
            ->join('proyectos as p', 'p.id_proyecto', '=', 'par.id_proyecto')
            ->join('uso_tecnologias as ut', 'ut.id_proyecto', '=', 'p.id_proyecto')
            ->join('tecnologias as t', 't.id_tecnologia', '=', 'ut.id_tecnologia')
            ->where('par.id_usuario', $usuarioId)
            ->where('par.visibilidad', 'publico')
            ->whereNull('par.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereNull('ut.deleted_at')
            ->whereRaw('ut.es_visible IS TRUE')
            ->where('t.nombre', 'ilike', $like)
            ->exists();
    }

    private function formatearEvento(object $evento): array
    {
        $cupo = (int) $evento->cupo;
        $inscritos = (int) $evento->inscritos;

        $data = [
            'id_evento' => $evento->id_evento,
            'titulo' => $evento->titulo,
            'descripcion' => $evento->descripcion,
            'tipo' => $evento->tipo,
            'estado' => $evento->estado,
            'fecha_inicio' => $evento->fecha_inicio,
            'fecha_fin' => $evento->fecha_fin,
            'programado_para' => $evento->programado_para,
            'ubicacion' => $evento->ubicacion,

            'cupo' => $cupo,
            'inscritos' => $inscritos,
            'cupo_disponible' => $cupo === 0 ? null : max($cupo - $inscritos, 0),

            'esta_inscrito' => (bool) $evento->esta_inscrito,
            'id_inscripcion' => $evento->id_inscripcion,
            'fecha_inscripcion' => $evento->fecha_inscripcion,

            'canales' => $this->jsonToArray($evento->channels ?? null),
        ];

        if (property_exists($evento, 'imagen_portada_path')) {
            $data['imagen_portada_path'] = $evento->imagen_portada_path;
        }

        if (property_exists($evento, 'imagen_portada_url')) {
            $data['imagen_portada_url'] = $evento->imagen_portada_url;
        }

        return $data;
    }

    private function fechaDesde(array $data): ?string
    {
        $valor = data_get($data, 'fecha_desde')
            ?? data_get($data, 'fechaDesde')
            ?? data_get($data, 'desde');

        if (!$valor) {
            return null;
        }

        return Carbon::parse($valor)->toDateString();
    }

    private function queryTokens(string $query): array
    {
        return collect(preg_split('/\s+/', $this->normalizarTexto($query)))
            ->filter(fn ($token) => mb_strlen($token) >= 2)
            ->unique()
            ->values()
            ->toArray();
    }

    private function jsonToArray($json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        if (is_array($json)) {
            return $json;
        }

        $data = json_decode((string) $json, true);

        return is_array($data) ? $data : [];
    }

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

    private function normalizarLista($valor): array
    {
        if ($valor === null || $valor === '' || $valor === false) {
            return [];
        }

        if (is_string($valor) || is_numeric($valor)) {
            return [$this->normalizarTexto($valor)];
        }

        if (is_array($valor)) {
            $resultado = [];

            foreach ($valor as $item) {
                if (is_array($item)) {
                    if (isset($item['value'])) {
                        $resultado[] = $this->normalizarTexto($item['value']);
                        continue;
                    }

                    if (isset($item['nombre'])) {
                        $resultado[] = $this->normalizarTexto($item['nombre']);
                        continue;
                    }

                    if (isset($item['name'])) {
                        $resultado[] = $this->normalizarTexto($item['name']);
                        continue;
                    }

                    if (isset($item['label'])) {
                        $resultado[] = $this->normalizarTexto($item['label']);
                        continue;
                    }
                }

                $resultado = array_merge($resultado, $this->normalizarLista($item));
            }

            return array_values(array_unique(array_filter($resultado)));
        }

        return [];
    }

    private function normalizarTexto($valor): string
    {
        return Str::of((string) $valor)
            ->lower()
            ->ascii()
            ->trim()
            ->toString();
    }

    private function extraerIdsUsuarios(array $data): array
    {
        $ids = [];

        foreach ($data as $key => $value) {
            $keyNormalizado = $this->normalizarTexto($key);

            if (in_array($keyNormalizado, [
                'usuarios',
                'usuarios_ids',
                'usuario_ids',
                'ids_usuarios',
                'selected_users',
                'selected_user_ids',
                'users',
                'user_ids',
            ], true)) {
                $ids = array_merge($ids, $this->extraerNumeros($value));
                continue;
            }

            if (is_array($value)) {
                $ids = array_merge($ids, $this->extraerIdsUsuarios($value));
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function extraerNumeros($value): array
    {
        if (is_numeric($value)) {
            return [(int) $value];
        }

        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            if (is_numeric($item)) {
                $ids[] = (int) $item;
                continue;
            }

            if (is_array($item)) {
                $ids = array_merge($ids, $this->extraerNumeros($item));
            }
        }

        return $ids;
    }

    private function sinAccion(string $mensaje): array
    {
        return [
            'accion' => false,
            'mensaje' => $mensaje,
            'datos' => null,
        ];
    }
}