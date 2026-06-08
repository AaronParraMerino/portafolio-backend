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

    public function verPublicos(int $perPage = 12): LengthAwarePaginator
    {
        $eventos = $this->baseEventosQuery(0)
            ->where(function ($query) {
                $query->where('e.target_mode', 'all_users')
                    ->orWhereNull('e.target_mode');
            })
            ->orderByRaw('CASE WHEN e.fecha_inicio IS NULL THEN 1 ELSE 0 END')
            ->orderBy('e.fecha_inicio')
            ->orderByDesc('e.created_at')
            ->get()
            ->map(fn ($evento) => $this->formatearEventoPublico($evento))
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
            'e.target_mode',
            'e.channels',
            'e.segments',
            'e.target_selections',
            'e.updated_at',
            'uc.nombre as creador_nombre',
            'uc.apellido as creador_apellido',
            'uc.correo as creador_correo',
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
            ->leftJoin('usuarios as uc', 'uc.id_usuario', '=', 'e.usuario_creador_id')
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

        return !$evento->fecha_fin || Carbon::parse($evento->fecha_fin)->gte(now());
    }

    private function usuarioPuedeVerEvento(int $usuarioId, object $evento): bool
    {
        $targetMode = $this->normalizarTexto($evento->target_mode ?? 'all_users');

        if ($targetMode === 'all_users') {
            return true;
        }

        if ($targetMode !== 'segmented') {
            return false;
        }

        $criterios = $this->criteriosSegmentacionEvento(
            $this->jsonToArray($evento->target_selections ?? null),
            $this->jsonToArray($evento->segments ?? null)
        );

        return $this->tieneCriterios($criterios)
            && $this->usuarioCumpleSegmentacion($usuarioId, $criterios);
    }

    private function criteriosSegmentacionEvento(array $targetSelections, array $segments): array
    {
        return [
            'habilidades_tecnicas' => $this->valoresUnicos(array_merge(
                $this->normalizarLista(data_get($targetSelections, 'technicalSkills', [])),
                $this->normalizarLista(data_get($segments, 'habilidades.tecnicas.items', []))
            )),
            'habilidades_blandas' => $this->valoresUnicos(array_merge(
                $this->normalizarLista(data_get($targetSelections, 'softSkills', [])),
                $this->normalizarLista(data_get($segments, 'habilidades.blandas.items', []))
            )),
            'experiencia_academica' => $this->valoresUnicosTexto(array_merge(
                $this->listaTextoOriginal(data_get($targetSelections, 'academicExperience', [])),
                $this->experienciasPorTipo($segments, 'academica')
            )),
            'experiencia_laboral' => $this->valoresUnicosTexto(array_merge(
                $this->listaTextoOriginal(data_get($targetSelections, 'workExperience', [])),
                $this->experienciasPorTipo($segments, 'laboral')
            )),
        ];
    }

    private function experienciasPorTipo(array $segments, string $tipoBuscado): array
    {
        $experiencias = data_get($segments, 'experiencia', []);

        if (!is_array($experiencias)) {
            return [];
        }

        $resultado = [];

        foreach ($experiencias as $item) {
            if (!is_array($item)) {
                continue;
            }

            $cargo = trim((string) data_get($item, 'cargo', ''));

            if ($cargo === '') {
                continue;
            }

            $tipos = $this->normalizarLista(data_get($item, 'tipos', []));

            if (in_array($tipoBuscado, $tipos, true)) {
                $resultado[] = $cargo;
            }
        }

        return $resultado;
    }

    private function usuarioCumpleSegmentacion(int $usuarioId, array $criterios): bool
    {
        $habilidadesTecnicas = data_get($criterios, 'habilidades_tecnicas', []);

        if (!empty($habilidadesTecnicas)
            && !$this->usuarioTieneHabilidad($usuarioId, 'tecnica', $habilidadesTecnicas)) {
            return false;
        }

        $habilidadesBlandas = data_get($criterios, 'habilidades_blandas', []);

        if (!empty($habilidadesBlandas)
            && !$this->usuarioTieneHabilidad($usuarioId, 'blanda', $habilidadesBlandas)) {
            return false;
        }

        $experienciaAcademica = data_get($criterios, 'experiencia_academica', []);

        if (!empty($experienciaAcademica)
            && !$this->usuarioTieneExperiencia($usuarioId, $experienciaAcademica, 'academica')) {
            return false;
        }

        $experienciaLaboral = data_get($criterios, 'experiencia_laboral', []);

        if (!empty($experienciaLaboral)
            && !$this->usuarioTieneExperiencia($usuarioId, $experienciaLaboral, 'laboral')) {
            return false;
        }

        return true;
    }

    private function usuarioTieneHabilidad(int $usuarioId, string $tipo, array $habilidades): bool
    {
        return DB::table('habilidades_usuario as hu')
            ->join('habilidades as hb', 'hb.id_habilidad', '=', 'hu.habilidad_id')
            ->where('hu.usuario_id', $usuarioId)
            ->where('hb.tipo', $tipo)
            ->whereRaw('hu.es_visible IS TRUE')
            ->whereRaw('hb.estado IS TRUE')
            ->whereIn('hb.nombre_normalizado', $habilidades)
            ->exists();
    }

    private function usuarioTieneExperiencia(int $usuarioId, array $cargos, string $tipo): bool
    {
        $q = DB::table('experiencias as ex')
            ->where('ex.usuario_id', $usuarioId)
            ->whereRaw('ex.es_publico IS TRUE')
            ->whereRaw('LOWER(ex.tipo) = ?', [$tipo]);

        $q->where(function ($w) use ($cargos) {
            foreach ($cargos as $cargo) {
                $cargo = trim((string) $cargo);

                if ($cargo !== '') {
                    $w->orWhere('ex.cargo', 'ilike', "%{$cargo}%");
                }
            }
        });

        return $q->exists();
    }

    private function formatearEvento(object $evento): array
    {
        $cupo = (int) $evento->cupo;
        $inscritos = (int) $evento->inscritos;
        $nombreCreador = trim((string) (($evento->creador_nombre ?? '') . ' ' . ($evento->creador_apellido ?? '')));
        $nombreCreador = $nombreCreador !== '' ? $nombreCreador : null;

        $data = [
            'id_evento' => $evento->id_evento,
            'usuario_creador_id' => $evento->usuario_creador_id,
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
            'updated_at' => $evento->updated_at,
        ];

        if ($nombreCreador !== null) {
            $data['autor_nombre'] = $nombreCreador;
            $data['creador'] = [
                'id' => $evento->usuario_creador_id,
                'id_usuario' => $evento->usuario_creador_id,
                'nombre' => $nombreCreador,
                'correo' => $evento->creador_correo,
            ];
        }

        if (property_exists($evento, 'imagen_portada_path')) {
            $data['imagen_portada_path'] = $evento->imagen_portada_path;
        }

        if (property_exists($evento, 'imagen_portada_url')) {
            $data['imagen_portada_url'] = $evento->imagen_portada_url;
        }

        return $data;
    }

    private function formatearEventoPublico(object $evento): array
    {
        $data = $this->formatearEvento($evento);
        $data['esta_inscrito'] = false;
        $data['id_inscripcion'] = null;
        $data['fecha_inscripcion'] = null;

        if (isset($data['creador'])) {
            unset($data['creador']['correo']);
        }

        return $data;
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

    private function tieneCriterios(array $criterios): bool
    {
        foreach ($criterios as $items) {
            if (!empty($items)) {
                return true;
            }
        }

        return false;
    }

    private function normalizarLista($valor): array
    {
        if ($valor === null || $valor === '' || $valor === false) {
            return [];
        }

        if (is_string($valor) || is_numeric($valor)) {
            return [$this->normalizarTexto($valor)];
        }

        if (!is_array($valor)) {
            return [];
        }

        $resultado = [];

        foreach ($valor as $item) {
            $resultado = array_merge($resultado, $this->normalizarLista($item));
        }

        return $this->valoresUnicos($resultado);
    }

    private function listaTextoOriginal($valor): array
    {
        if ($valor === null || $valor === '' || $valor === false) {
            return [];
        }

        if (is_string($valor) || is_numeric($valor)) {
            $texto = trim((string) $valor);

            return $texto === '' ? [] : [$texto];
        }

        if (!is_array($valor)) {
            return [];
        }

        $resultado = [];

        foreach ($valor as $item) {
            $resultado = array_merge($resultado, $this->listaTextoOriginal($item));
        }

        return $this->valoresUnicosTexto($resultado);
    }

    private function valoresUnicos(array $valores): array
    {
        return array_values(array_unique(array_filter($valores, fn ($valor) => $valor !== '')));
    }

    private function valoresUnicosTexto(array $valores): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($valor) => trim((string) $valor),
            $valores
        ), fn ($valor) => $valor !== '')));
    }

    private function normalizarTexto($valor): string
    {
        return Str::of((string) $valor)
            ->lower()
            ->ascii()
            ->trim()
            ->toString();
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
