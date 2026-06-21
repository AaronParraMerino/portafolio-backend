<?php

namespace App\Services\api\Notificaciones;

use App\Services\api\EventosNotificacionGuardadoService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificacionPendienteService
{
    public function __construct(
        private readonly NotificacionSupportService $support,
        private readonly EventosNotificacionGuardadoService $eventosNotificacionGuardadoService
    ) {
    }

    public function obtenerResumenModulosNoLeidos(int $idUsuario): array
    {
        $this->eventosNotificacionGuardadoService->generarNotificacionesGeneralesProximas($idUsuario);

        $conteos = $this->support->consultaBaseNoLeidas($idUsuario)
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
        ];
    }

    public function obtenerSegundoNivelPorModulo(int $idUsuario, string $modulo): array
    {
        $modulo = $this->support->normalizarModulo($modulo);

        if (! $this->support->moduloValido($modulo)) {
            return [
                'status' => 'invalid_module',
                'message' => 'Modulo no valido',
            ];
        }

        if ($modulo === NotificacionSupportService::MODULO_ADMINISTRACION) {
            return [
                'status' => 'success',
                'modulo' => $modulo,
                'tipo_vista' => 'mensajes_directos',
                'data' => $this->obtenerMensajesAdministracionNoLeidos($idUsuario),
            ];
        }

        $grupos = $this->support->consultaBaseNoLeidas($idUsuario)
            ->where('n.modulo', $modulo)
            ->select(
                'n.contexto_referencia',
                'n.grupo_titulo',
                DB::raw('COUNT(*) as cantidad'),
                DB::raw('MAX(n.created_at) as ultimo_creado_en')
            )
            ->groupBy('n.contexto_referencia', 'n.grupo_titulo')
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
        ];
    }

    public function obtenerMensajesNoLeidosPorGrupo(
        int $idUsuario,
        string $modulo,
        string $contextoReferencia
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

        $mensajes = $this->support->consultaBaseNoLeidas($idUsuario)
            ->where('n.modulo', $modulo)
            ->where('n.contexto_referencia', $contextoReferencia)
            ->orderByDesc('n.created_at')
            ->select($this->support->camposMensaje())
            ->get()
            ->map(fn ($row) => $this->support->formatearMensaje($row))
            ->values()
            ->all();

        return [
            'status' => 'success',
            'modulo' => $modulo,
            'contexto_referencia' => $contextoReferencia,
            'data' => $mensajes,
            'total' => count($mensajes),
        ];
    }

    private function obtenerMensajesAdministracionNoLeidos(int $idUsuario): array
    {
        return $this->support->consultaBaseNoLeidas($idUsuario)
            ->where('n.modulo', NotificacionSupportService::MODULO_ADMINISTRACION)
            ->orderByDesc('n.created_at')
            ->select($this->support->camposMensaje())
            ->get()
            ->map(fn ($row) => $this->support->formatearMensaje($row))
            ->values()
            ->all();
    }
}
