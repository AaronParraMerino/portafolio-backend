<?php

namespace App\Services\api\Notificaciones;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificacionLecturaService
{
    public function __construct(
        private readonly NotificacionSupportService $support
    ) {
    }

    public function marcarNotificacionComoLeida(int $idUsuario, int $idNotificacion): array
    {
        $actualizadas = DB::table('notificacion_usuario')
            ->where('id_usuario', $idUsuario)
            ->where('id_notificacion', $idNotificacion)
            ->whereNull('leido_en')
            ->update([
                'leido_en' => now(),
                'updated_at' => now(),
            ]);

        if ($actualizadas <= 0) {
            $notificacion = $this->support->obtenerNotificacionDelUsuario($idUsuario, $idNotificacion);
            if ($notificacion) {
                return [
                    'status' => 'success',
                    'message' => 'Notificacion ya estaba leida',
                    'actualizadas' => 0,
                    'data' => $notificacion,
                    'resumen' => [
                        'pendientes' => $this->contarNoLeidas($idUsuario),
                    ],
                ];
            }

            return [
                'status' => 'not_found',
                'message' => 'Notificacion no encontrada o ya estaba leida',
                'actualizadas' => 0,
            ];
        }

        $notificacion = $this->support->obtenerNotificacionDelUsuario($idUsuario, $idNotificacion);

        return [
            'status' => 'success',
            'message' => 'Notificacion marcada como leida',
            'actualizadas' => $actualizadas,
            'data' => $notificacion,
            'resumen' => [
                'pendientes' => $this->contarNoLeidas($idUsuario),
            ],
        ];
    }

    public function marcarNotificacionComoNoLeida(int $idUsuario, int $idNotificacion): array
    {
        $actualizadas = DB::table('notificacion_usuario')
            ->where('id_usuario', $idUsuario)
            ->where('id_notificacion', $idNotificacion)
            ->whereNotNull('leido_en')
            ->update([
                'leido_en' => null,
                'updated_at' => now(),
            ]);

        if ($actualizadas <= 0) {
            $notificacion = $this->support->obtenerNotificacionDelUsuario($idUsuario, $idNotificacion);
            if ($notificacion) {
                return [
                    'status' => 'success',
                    'message' => 'Notificacion ya estaba pendiente',
                    'actualizadas' => 0,
                    'data' => $notificacion,
                    'resumen' => [
                        'pendientes' => $this->contarNoLeidas($idUsuario),
                    ],
                ];
            }

            return [
                'status' => 'not_found',
                'message' => 'Notificacion no encontrada o ya estaba pendiente',
                'actualizadas' => 0,
            ];
        }

        $notificacion = $this->support->obtenerNotificacionDelUsuario($idUsuario, $idNotificacion);

        return [
            'status' => 'success',
            'message' => 'Notificacion marcada como no leida',
            'actualizadas' => $actualizadas,
            'data' => $notificacion,
            'resumen' => [
                'pendientes' => $this->contarNoLeidas($idUsuario),
            ],
        ];
    }

    public function marcarGrupoComoLeido(
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

        $idsPivot = $this->support->consultaBaseNoLeidas($idUsuario)
            ->where('n.modulo', $modulo)
            ->where('n.contexto_referencia', $contextoReferencia)
            ->pluck('nu.id_notificacion_usuario');

        $actualizadas = $this->marcarPivotsComoLeidos($idsPivot);

        return [
            'status' => 'success',
            'message' => 'Grupo marcado como leido',
            'actualizadas' => $actualizadas,
            'resumen' => [
                'pendientes' => $this->contarNoLeidas($idUsuario),
            ],
        ];
    }

    public function marcarModuloComoLeido(int $idUsuario, string $modulo): array
    {
        $modulo = $this->support->normalizarModulo($modulo);

        if (! $this->support->moduloValido($modulo)) {
            return [
                'status' => 'invalid_module',
                'message' => 'Modulo no valido',
            ];
        }

        $idsPivot = $this->support->consultaBaseNoLeidas($idUsuario)
            ->where('n.modulo', $modulo)
            ->pluck('nu.id_notificacion_usuario');

        $actualizadas = $this->marcarPivotsComoLeidos($idsPivot);

        return [
            'status' => 'success',
            'message' => 'Modulo marcado como leido',
            'actualizadas' => $actualizadas,
            'resumen' => [
                'pendientes' => $this->contarNoLeidas($idUsuario),
            ],
        ];
    }

    public function marcarTodasComoLeidas(int $idUsuario): array
    {
        $actualizadas = DB::table('notificacion_usuario')
            ->where('id_usuario', $idUsuario)
            ->whereNull('leido_en')
            ->update([
                'leido_en' => now(),
                'updated_at' => now(),
            ]);

        return [
            'status' => 'success',
            'message' => 'Todas las notificaciones fueron marcadas como leidas',
            'actualizadas' => $actualizadas,
            'resumen' => [
                'pendientes' => 0,
            ],
        ];
    }

    public function contarNoLeidas(int $idUsuario): int
    {
        return DB::table('notificacion_usuario')
            ->where('id_usuario', $idUsuario)
            ->whereNull('leido_en')
            ->count();
    }

    private function marcarPivotsComoLeidos(Collection $idsPivot): int
    {
        $ids = $idsPivot
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::table('notificacion_usuario')
            ->whereIn('id_notificacion_usuario', $ids->all())
            ->whereNull('leido_en')
            ->update([
                'leido_en' => now(),
                'updated_at' => now(),
            ]);
    }
}
