<?php

namespace App\Services\api;

use App\Models\Aviso;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminAvisoService
{
    private const ESTADOS = [
        Aviso::ESTADO_ACTIVO,
        Aviso::ESTADO_INACTIVO,
        Aviso::ESTADO_ELIMINADO,
    ];

    private const PRIORIDADES = [
        Aviso::PRIORIDAD_BAJA,
        Aviso::PRIORIDAD_NORMAL,
        Aviso::PRIORIDAD_ALTA,
        Aviso::PRIORIDAD_CRITICA,
    ];

    private const TIPOS = [
        'operacional_tecnico',
        'negocio_logistica_eventos',
        'comunicacion_marketing_global',
        'legal_cumplimiento',
    ];

    /**
     * Lista avisos para administracion
     */
    public function listar(array $filtros = [], int $porPagina = 12): array
    {
        $porPagina = max(1, min($porPagina, 100));

        if (isset($filtros['estado']) && !$this->estadoValido($filtros['estado'])) {
            return [
                'status' => 'invalid_filter',
                'message' => 'Estado no valido',
            ];
        }

        if (isset($filtros['prioridad']) && !$this->prioridadValida($filtros['prioridad'])) {
            return [
                'status' => 'invalid_filter',
                'message' => 'Prioridad no valida',
            ];
        }

        $avisos = Aviso::query()
            ->with('usuarioActor')
            ->when($filtros['estado'] ?? null, function ($query, string $estado) {
                $query->where('estado', $this->normalizarTexto($estado));
            })
            ->when($filtros['prioridad'] ?? null, function ($query, string $prioridad) {
                $query->where('prioridad', $this->normalizarTexto($prioridad));
            })
            ->when($filtros['tipo'] ?? null, function ($query, string $tipo) {
                $query->where('tipo', $this->normalizarTexto($tipo));
            })
            ->when($filtros['buscar'] ?? null, function ($query, string $buscar) {
                $buscar = trim($buscar);

                $query->where(function ($q) use ($buscar) {
                    $q->where('titulo', 'like', "%{$buscar}%")
                        ->orWhere('mensaje', 'like', "%{$buscar}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate($porPagina);

        return [
            'status' => 'success',
            'data' => collect($avisos->items())
                ->map(fn (Aviso $aviso) => $this->formatearAviso($aviso))
                ->values()
                ->all(),
            'paginacion' => [
                'pagina_actual' => $avisos->currentPage(),
                'por_pagina' => $avisos->perPage(),
                'total' => $avisos->total(),
                'ultima_pagina' => $avisos->lastPage(),
                'desde' => $avisos->firstItem(),
                'hasta' => $avisos->lastItem(),
            ],
        ];
    }

    /**
     * Obtiene un aviso por id
     */
    public function ver(int $idAviso): array
    {
        $aviso = Aviso::with('usuarioActor')->find($idAviso);

        if (!$aviso) {
            return [
                'status' => 'not_found',
                'message' => 'Aviso no encontrado',
            ];
        }

        return [
            'status' => 'success',
            'data' => $this->formatearAviso($aviso),
        ];
    }

    /**
     * Crea un aviso
     */
    public function crear(int $idUsuarioActor, array $data): array
    {
        $payload = $this->prepararPayloadCreacion($data);

        if ($payload['status'] !== 'success') {
            return $payload;
        }

        $aviso = DB::transaction(function () use ($idUsuarioActor, $payload) {
            return Aviso::create([
                'id_usuario_actor' => $idUsuarioActor,
                'tipo' => $payload['data']['tipo'],
                'titulo' => $payload['data']['titulo'],
                'mensaje' => $payload['data']['mensaje'],
                'visible_desde' => $payload['data']['visible_desde'],
                'visible_hasta' => $payload['data']['visible_hasta'],
                'estado' => $payload['data']['estado'],
                'prioridad' => $payload['data']['prioridad'],
            ]);
        });

        $aviso->load('usuarioActor');

        return [
            'status' => 'success',
            'message' => 'Aviso creado correctamente',
            'data' => $this->formatearAviso($aviso),
        ];
    }

    /**
     * Actualiza un aviso
     */
    public function actualizar(int $idAviso, array $data): array
    {
        $aviso = Aviso::find($idAviso);

        if (!$aviso) {
            return [
                'status' => 'not_found',
                'message' => 'Aviso no encontrado',
            ];
        }

        $payload = $this->prepararPayloadActualizacion($data, $aviso);

        if ($payload['status'] !== 'success') {
            return $payload;
        }

        if (empty($payload['data'])) {
            return [
                'status' => 'invalid_payload',
                'message' => 'No hay datos para actualizar',
            ];
        }

        DB::transaction(function () use ($aviso, $payload) {
            $aviso->update($payload['data']);
        });

        $aviso->refresh()->load('usuarioActor');

        return [
            'status' => 'success',
            'message' => 'Aviso actualizado correctamente',
            'data' => $this->formatearAviso($aviso),
        ];
    }

    /**
     * Cambia el estado del aviso
     */
    public function cambiarEstado(int $idAviso, string $estado): array
    {
        $estado = $this->normalizarTexto($estado);

        if (!$this->estadoValido($estado)) {
            return [
                'status' => 'invalid_payload',
                'message' => 'Estado no valido',
            ];
        }

        $aviso = Aviso::find($idAviso);

        if (!$aviso) {
            return [
                'status' => 'not_found',
                'message' => 'Aviso no encontrado',
            ];
        }

        $aviso->update([
            'estado' => $estado,
        ]);

        $aviso->load('usuarioActor');

        return [
            'status' => 'success',
            'message' => 'Estado actualizado correctamente',
            'data' => $this->formatearAviso($aviso),
        ];
    }

    /**
     * Cambia la prioridad del aviso
     */
    public function cambiarPrioridad(int $idAviso, string $prioridad): array
    {
        $prioridad = $this->normalizarTexto($prioridad);

        if (!$this->prioridadValida($prioridad)) {
            return [
                'status' => 'invalid_payload',
                'message' => 'Prioridad no valida',
            ];
        }

        $aviso = Aviso::find($idAviso);

        if (!$aviso) {
            return [
                'status' => 'not_found',
                'message' => 'Aviso no encontrado',
            ];
        }

        $aviso->update([
            'prioridad' => $prioridad,
        ]);

        $aviso->load('usuarioActor');

        return [
            'status' => 'success',
            'message' => 'Prioridad actualizada correctamente',
            'data' => $this->formatearAviso($aviso),
        ];
    }

    /**
     * Eliminacion logica del aviso
     */
    public function eliminarLogico(int $idAviso): array
    {
        return $this->cambiarEstado($idAviso, Aviso::ESTADO_ELIMINADO);
    }

    private function prepararPayloadCreacion(array $data): array
    {
        $tipo = $this->normalizarTexto($data['tipo'] ?? '');
        $titulo = trim((string) ($data['titulo'] ?? ''));
        $mensaje = trim((string) ($data['mensaje'] ?? ''));

        if ($tipo === '') {
            return [
                'status' => 'invalid_payload',
                'message' => 'El tipo es obligatorio',
            ];
        }

        if (!$this->tipoValido($tipo)) {
            return [
                'status' => 'invalid_payload',
                'message' => 'Tipo de aviso no valido',
            ];
        }

        if ($titulo === '') {
            return [
                'status' => 'invalid_payload',
                'message' => 'El titulo es obligatorio',
            ];
        }

        if ($mensaje === '') {
            return [
                'status' => 'invalid_payload',
                'message' => 'El mensaje es obligatorio',
            ];
        }

        $estado = $this->normalizarTexto($data['estado'] ?? Aviso::ESTADO_ACTIVO);
        $prioridad = $this->normalizarTexto($data['prioridad'] ?? Aviso::PRIORIDAD_NORMAL);

        if (!$this->estadoValido($estado)) {
            return [
                'status' => 'invalid_payload',
                'message' => 'Estado no valido',
            ];
        }

        if (!$this->prioridadValida($prioridad)) {
            return [
                'status' => 'invalid_payload',
                'message' => 'Prioridad no valida',
            ];
        }

        $visibleDesde = $this->normalizarFecha($data['visible_desde'] ?? null);

        if ($visibleDesde['status'] !== 'success') {
            return $visibleDesde;
        }

        $visibleHasta = $this->normalizarFecha($data['visible_hasta'] ?? null);

        if ($visibleHasta['status'] !== 'success') {
            return $visibleHasta;
        }

        if (!$this->rangoFechasValido($visibleDesde['data'], $visibleHasta['data'])) {
            return [
                'status' => 'invalid_payload',
                'message' => 'La fecha visible_desde no puede ser mayor a visible_hasta',
            ];
        }

        return [
            'status' => 'success',
            'data' => [
                'tipo' => $tipo,
                'titulo' => $titulo,
                'mensaje' => $mensaje,
                'visible_desde' => $visibleDesde['data'],
                'visible_hasta' => $visibleHasta['data'],
                'estado' => $estado,
                'prioridad' => $prioridad,
            ],
        ];
    }

    private function prepararPayloadActualizacion(array $data, Aviso $aviso): array
    {
        $payload = [];

        if (array_key_exists('tipo', $data)) {
            $tipo = $this->normalizarTexto($data['tipo']);

            if ($tipo === '') {
                return [
                    'status' => 'invalid_payload',
                    'message' => 'El tipo no puede estar vacio',
                ];
            }

            if (!$this->tipoValido($tipo)) {
                return [
                    'status' => 'invalid_payload',
                    'message' => 'Tipo de aviso no valido',
                ];
            }

            $payload['tipo'] = $tipo;
        }

        if (array_key_exists('titulo', $data)) {
            $titulo = trim((string) $data['titulo']);

            if ($titulo === '') {
                return [
                    'status' => 'invalid_payload',
                    'message' => 'El titulo no puede estar vacio',
                ];
            }

            $payload['titulo'] = $titulo;
        }

        if (array_key_exists('mensaje', $data)) {
            $mensaje = trim((string) $data['mensaje']);

            if ($mensaje === '') {
                return [
                    'status' => 'invalid_payload',
                    'message' => 'El mensaje no puede estar vacio',
                ];
            }

            $payload['mensaje'] = $mensaje;
        }

        if (array_key_exists('estado', $data)) {
            $estado = $this->normalizarTexto($data['estado']);

            if (!$this->estadoValido($estado)) {
                return [
                    'status' => 'invalid_payload',
                    'message' => 'Estado no valido',
                ];
            }

            $payload['estado'] = $estado;
        }

        if (array_key_exists('prioridad', $data)) {
            $prioridad = $this->normalizarTexto($data['prioridad']);

            if (!$this->prioridadValida($prioridad)) {
                return [
                    'status' => 'invalid_payload',
                    'message' => 'Prioridad no valida',
                ];
            }

            $payload['prioridad'] = $prioridad;
        }

        if (array_key_exists('visible_desde', $data)) {
            $visibleDesde = $this->normalizarFecha($data['visible_desde']);

            if ($visibleDesde['status'] !== 'success') {
                return $visibleDesde;
            }

            $payload['visible_desde'] = $visibleDesde['data'];
        }

        if (array_key_exists('visible_hasta', $data)) {
            $visibleHasta = $this->normalizarFecha($data['visible_hasta']);

            if ($visibleHasta['status'] !== 'success') {
                return $visibleHasta;
            }

            $payload['visible_hasta'] = $visibleHasta['data'];
        }

        $fechaDesdeFinal = array_key_exists('visible_desde', $payload)
            ? $payload['visible_desde']
            : $aviso->visible_desde;

        $fechaHastaFinal = array_key_exists('visible_hasta', $payload)
            ? $payload['visible_hasta']
            : $aviso->visible_hasta;

        if (!$this->rangoFechasValido($fechaDesdeFinal, $fechaHastaFinal)) {
            return [
                'status' => 'invalid_payload',
                'message' => 'La fecha visible_desde no puede ser mayor a visible_hasta',
            ];
        }

        return [
            'status' => 'success',
            'data' => $payload,
        ];
    }

    private function estadoValido(?string $estado): bool
    {
        return in_array($this->normalizarTexto($estado), self::ESTADOS, true);
    }

    private function prioridadValida(?string $prioridad): bool
    {
        return in_array($this->normalizarTexto($prioridad), self::PRIORIDADES, true);
    }

    private function tipoValido(string $tipo): bool
    {
        return in_array($this->normalizarTexto($tipo), self::TIPOS, true);
    }

    private function normalizarTexto(?string $texto): string
    {
        return strtolower(trim((string) $texto));
    }

    private function normalizarFecha($fecha): array
    {
        if ($fecha === null || trim((string) $fecha) === '') {
            return [
                'status' => 'success',
                'data' => null,
            ];
        }

        try {
            return [
                'status' => 'success',
                'data' => Carbon::parse($fecha)->format('Y-m-d H:i:s'),
            ];
        } catch (Throwable) {
            return [
                'status' => 'invalid_payload',
                'message' => 'Formato de fecha no valido',
            ];
        }
    }

    private function rangoFechasValido($visibleDesde, $visibleHasta): bool
    {
        if (!$visibleDesde || !$visibleHasta) {
            return true;
        }

        return Carbon::parse($visibleDesde)->lessThanOrEqualTo(
            Carbon::parse($visibleHasta)
        );
    }

    private function formatearAviso(Aviso $aviso): array
    {
        $actor = $aviso->usuarioActor;

        return [
            'id_aviso' => (int) $aviso->id_aviso,
            'id_usuario_actor' => $aviso->id_usuario_actor ? (int) $aviso->id_usuario_actor : null,
            'tipo' => $aviso->tipo,
            'titulo' => $aviso->titulo,
            'mensaje' => $aviso->mensaje,
            'visible_desde' => $aviso->visible_desde?->toDateTimeString(),
            'visible_hasta' => $aviso->visible_hasta?->toDateTimeString(),
            'estado' => $aviso->estado,
            'prioridad' => $aviso->prioridad,
            'created_at' => $aviso->created_at?->toDateTimeString(),
            'updated_at' => $aviso->updated_at?->toDateTimeString(),
            'actor' => $actor ? [
                'id_usuario' => (int) $actor->id_usuario,
                'nombre' => $actor->nombre ?? null,
            ] : null,
        ];
    }
}
