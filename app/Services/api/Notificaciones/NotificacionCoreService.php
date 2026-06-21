<?php

namespace App\Services\api\Notificaciones;

class NotificacionCoreService
{
    public function __construct(
        private readonly NotificacionPendienteService $pendientes,
        private readonly NotificacionLeidaService $leidas,
        private readonly NotificacionLecturaService $lectura,
        private readonly NotificacionAccionPersonalService $accionPersonalService
    ) {
    }

    public function obtenerResumenModulosNoLeidos(int $idUsuario): array
    {
        return $this->pendientes->obtenerResumenModulosNoLeidos($idUsuario);
    }

    public function obtenerSegundoNivelPorModulo(int $idUsuario, string $modulo): array
    {
        return $this->pendientes->obtenerSegundoNivelPorModulo($idUsuario, $modulo);
    }

    public function obtenerMensajesNoLeidosPorGrupo(
        int $idUsuario,
        string $modulo,
        string $contextoReferencia
    ): array {
        return $this->pendientes->obtenerMensajesNoLeidosPorGrupo($idUsuario, $modulo, $contextoReferencia);
    }

    public function marcarNotificacionComoLeida(int $idUsuario, int $idNotificacion): array
    {
        return $this->lectura->marcarNotificacionComoLeida($idUsuario, $idNotificacion);
    }

    public function marcarNotificacionComoNoLeida(int $idUsuario, int $idNotificacion): array
    {
        return $this->lectura->marcarNotificacionComoNoLeida($idUsuario, $idNotificacion);
    }

    public function marcarGrupoComoLeido(
        int $idUsuario,
        string $modulo,
        string $contextoReferencia
    ): array {
        return $this->lectura->marcarGrupoComoLeido($idUsuario, $modulo, $contextoReferencia);
    }

    public function marcarModuloComoLeido(int $idUsuario, string $modulo): array
    {
        return $this->lectura->marcarModuloComoLeido($idUsuario, $modulo);
    }

    public function marcarTodasComoLeidas(int $idUsuario): array
    {
        return $this->lectura->marcarTodasComoLeidas($idUsuario);
    }

    public function contarNoLeidas(int $idUsuario): int
    {
        return $this->lectura->contarNoLeidas($idUsuario);
    }

    public function responderAccionPersonal(int $idUsuario, int $idNotificacion, string $accion): array
    {
        return $this->accionPersonalService->responder($idUsuario, $idNotificacion, $accion);
    }

    public function obtenerNotificacionesLeidas(
        int $idUsuario,
        ?string $modulo = null,
        int $porPagina = 20
    ): array {
        return $this->leidas->obtenerNotificacionesLeidas($idUsuario, $modulo, $porPagina);
    }

    public function obtenerResumenModulosLeidos(int $idUsuario): array
    {
        return $this->leidas->obtenerResumenModulosLeidos($idUsuario);
    }

    public function obtenerSegundoNivelLeidasPorModulo(
        int $idUsuario,
        string $modulo,
        int $porPagina = 20
    ): array {
        return $this->leidas->obtenerSegundoNivelLeidasPorModulo($idUsuario, $modulo, $porPagina);
    }

    public function obtenerMensajesLeidosPorGrupo(
        int $idUsuario,
        string $modulo,
        string $contextoReferencia,
        int $porPagina = 20
    ): array {
        return $this->leidas->obtenerMensajesLeidosPorGrupo($idUsuario, $modulo, $contextoReferencia, $porPagina);
    }
}
