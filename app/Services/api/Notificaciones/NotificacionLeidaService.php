<?php

namespace App\Services\api\Notificaciones;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificacionLeidaService
{
    public function __construct(
        private readonly NotificacionSupportService $support,
        private readonly NotificacionLecturaService $lectura
    ) {
    }

    public function obtenerNotificacionesLeidas(
        int $idUsuario,
        ?string $modulo = null,
        int $porPagina = 20
    ): array {
        $query = $this->support->consultaBaseLeidas($idUsuario)
            ->orderByDesc('nu.leido_en')
            ->orderByDesc('n.created_at')
            ->select($this->support->camposMensaje());

        if ($modulo !== null && trim($modulo) !== '') {
            $modulo = $this->support->normalizarModulo($modulo);

            if (! $this->support->moduloValido($modulo)) {
                return [
                    'status' => 'invalid_module',
                    'message' => 'Modulo no valido',
                ];
            }

            $query->where('n.modulo', $modulo);
        }

        $response = $this->support->paginarMensajes($query, $porPagina);

        return [
            'status' => 'success',
            'data' => $response['data'],
            'meta' => $response['meta'],
            'resumen' => [
                'pendientes' => $this->lectura->contarNoLeidas($idUsuario),
            ],
        ];
    }

    public function obtenerResumenModulosLeidos(int $idUsuario): array
    {
        $conteos = $this->support->consultaBaseLeidas($idUsuario)
            ->select('n.modulo', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('n.modulo')
            ->pluck('cantidad', 'modulo');

        $data = collect($this->support->modulosBase())
            ->map(function (array $modulo) use ($conteos) {
                $cantidad = (int) ($conteos[$modulo['modulo']] ?? 0);

                return [
                    'modulo' => $modulo['modulo'],
                    'titulo' => $modulo['titulo'],
                    'cantidad' => $cantidad,
                ];
            })
            ->values()
            ->all();

        return [
            'status' => 'success',
            'data' => $data,
            'total' => collect($data)->sum('cantidad'),
            'resumen' => [
                'pendientes' => $this->lectura->contarNoLeidas($idUsuario),
            ],
        ];
    }

    public function obtenerSegundoNivelLeidasPorModulo(
        int $idUsuario,
        string $modulo,
        int $porPagina = 20
    ): array {
        $modulo = $this->support->normalizarModulo($modulo);

        if (! $this->support->moduloValido($modulo)) {
            return [
                'status' => 'invalid_module',
                'message' => 'Modulo no valido',
            ];
        }

        if ($modulo === NotificacionSupportService::MODULO_ADMINISTRACION) {
            $query = $this->support->consultaBaseLeidas($idUsuario)
                ->where('n.modulo', NotificacionSupportService::MODULO_ADMINISTRACION)
                ->orderByDesc('nu.leido_en')
                ->orderByDesc('n.created_at')
                ->select($this->support->camposMensaje());

            $response = $this->support->paginarMensajes($query, $porPagina);

            return [
                'status' => 'success',
                'modulo' => $modulo,
                'tipo_vista' => 'mensajes_directos',
                'data' => $response['data'],
                'meta' => $response['meta'],
                'resumen' => [
                    'pendientes' => $this->lectura->contarNoLeidas($idUsuario),
                ],
            ];
        }

        $grupos = $this->support->consultaBaseLeidas($idUsuario)
            ->where('n.modulo', $modulo)
            ->select(
                'n.contexto_referencia',
                'n.grupo_titulo',
                DB::raw('COUNT(*) as cantidad'),
                DB::raw('MAX(nu.leido_en) as ultimo_leido_en'),
                DB::raw('MAX(n.created_at) as ultimo_creado_en')
            )
            ->groupBy('n.contexto_referencia', 'n.grupo_titulo')
            ->orderByDesc('ultimo_leido_en')
            ->orderByDesc('ultimo_creado_en')
            ->get()
            ->reduce(function (Collection $carry, $row) {
                $key = $row->contexto_referencia ?: '__sin_contexto__';
                $current = $carry->get($key);

                if ($current) {
                    $current['cantidad'] += (int) $row->cantidad;
                    $carry->put($key, $current);
                    return $carry;
                }

                $carry->put($key, [
                    'contexto_referencia' => $row->contexto_referencia,
                    'titulo' => $row->grupo_titulo ?: 'Sin grupo',
                    'cantidad' => (int) $row->cantidad,
                    'ultimo_leido_en' => $this->support->formatUtcDateTime($row->ultimo_leido_en),
                ]);

                return $carry;
            }, collect())
            ->values()
            ->all();

        return [
            'status' => 'success',
            'modulo' => $modulo,
            'tipo_vista' => 'grupos',
            'data' => $grupos,
            'total' => collect($grupos)->sum('cantidad'),
            'resumen' => [
                'pendientes' => $this->lectura->contarNoLeidas($idUsuario),
            ],
        ];
    }

    public function obtenerMensajesLeidosPorGrupo(
        int $idUsuario,
        string $modulo,
        string $contextoReferencia,
        int $porPagina = 20
    ): array {
        $modulo = $this->support->normalizarModulo($modulo);
        $contextoReferencia = trim($contextoReferencia);

        if (! $this->support->moduloValido($modulo)) {
            return [
                'status' => 'invalid_module',
                'message' => 'Modulo no valido',
            ];
        }

        if ($contextoReferencia === '') {
            return [
                'status' => 'invalid_payload',
                'message' => 'Debe enviar una referencia de grupo',
            ];
        }

        $query = $this->support->consultaBaseLeidas($idUsuario)
            ->where('n.modulo', $modulo)
            ->where('n.contexto_referencia', $contextoReferencia)
            ->orderByDesc('nu.leido_en')
            ->orderByDesc('n.created_at')
            ->select($this->support->camposMensaje());

        $response = $this->support->paginarMensajes($query, $porPagina);

        return [
            'status' => 'success',
            'modulo' => $modulo,
            'contexto_referencia' => $contextoReferencia,
            'data' => $response['data'],
            'meta' => $response['meta'],
            'resumen' => [
                'pendientes' => $this->lectura->contarNoLeidas($idUsuario),
            ],
        ];
    }
}
