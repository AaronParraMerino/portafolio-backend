<?php

namespace App\Services\api\Mensajeria;

class MensajeriaService
{
    public function __construct(
        private readonly MensajeriaSolicitudService $solicitudes,
        private readonly MensajeriaGrupoService $grupos,
        private readonly MensajeriaMensajeService $mensajes,
        private readonly MensajeriaPanelService $panel,
    ) {
    }

    public function estadoContactoPerfil(int $idUsuarioActual, int $idUsuarioObjetivo): array
    {
        return $this->solicitudes->estadoContactoPerfil($idUsuarioActual, $idUsuarioObjetivo);
    }

    public function crearSolicitudPrivada(int $idSolicitante, int $idDestinatario, string $mensajeInicial): array
    {
        return $this->solicitudes->crearSolicitudPrivada($idSolicitante, $idDestinatario, $mensajeInicial);
    }

    public function aceptarSolicitud(int $idUsuario, int $idSolicitud): array
    {
        return $this->solicitudes->aceptarSolicitud($idUsuario, $idSolicitud);
    }

    public function rechazarSolicitud(int $idUsuario, int $idSolicitud): array
    {
        return $this->solicitudes->rechazarSolicitud($idUsuario, $idSolicitud);
    }

    public function crearGrupo(int $idOwner, string $nombre, array $invitados = []): array
    {
        return $this->grupos->crearGrupo($idOwner, $nombre, $invitados);
    }

    public function invitarAGrupo(int $idInvitador, int $idChat, int $idInvitado): array
    {
        return $this->grupos->invitarAGrupo($idInvitador, $idChat, $idInvitado);
    }

    public function aceptarInvitacion(int $idUsuario, int $idInvitacion): array
    {
        return $this->grupos->aceptarInvitacion($idUsuario, $idInvitacion);
    }

    public function rechazarInvitacion(int $idUsuario, int $idInvitacion): array
    {
        return $this->grupos->rechazarInvitacion($idUsuario, $idInvitacion);
    }

    public function salirGrupo(int $idUsuario, int $idChat): array
    {
        return $this->grupos->salirGrupo($idUsuario, $idChat);
    }

    public function enviarMensajeTexto(int $idUsuario, int $idChat, string $contenido): array
    {
        return $this->mensajes->enviarMensajeTexto($idUsuario, $idChat, $contenido);
    }

    public function obtenerMensajes(int $idUsuario, int $idChat, ?int $antesDeMensajeId = null): array
    {
        return $this->mensajes->obtenerMensajes($idUsuario, $idChat, $antesDeMensajeId);
    }

    public function archivarChat(int $idUsuario, int $idChat, bool $archivado = true): array
    {
        return $this->mensajes->archivarChat($idUsuario, $idChat, $archivado);
    }

    public function bloquearPrivado(int $idUsuario, int $idChat): array
    {
        return $this->mensajes->bloquearPrivado($idUsuario, $idChat);
    }

    public function desbloquearPrivado(int $idUsuario, int $idChat): array
    {
        return $this->mensajes->desbloquearPrivado($idUsuario, $idChat);
    }

    public function listarPanel(int $idUsuario): array
    {
        return $this->panel->listarPanel($idUsuario);
    }
}
