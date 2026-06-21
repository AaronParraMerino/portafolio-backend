<?php

namespace App\Services\api\Mensajeria;

use App\Models\Chat;
use App\Models\ChatInvitacion;
use App\Models\ChatSolicitud;
use App\Models\Notificacion;
use App\Models\NotificacionUsuario;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class MensajeriaNotificacionService
{
    private const MODULO_PERSONALES = 'personales';

    public function notificarSolicitudChat(ChatSolicitud $solicitud): Notificacion
    {
        $solicitud->loadMissing(['solicitante', 'destinatario', 'chat']);

        $nombre = $this->nombreUsuario($solicitud->solicitante);
        $mensaje = $nombre . ' quiere iniciar una conversacion contigo.';

        return $this->crearAccionPersonal(
            actorId: $solicitud->id_solicitante,
            destinatarioId: $solicitud->id_destinatario,
            contextoTipo: 'chat_solicitud',
            contextoReferencia: (string) $solicitud->id_chat_solicitud,
            tipo: 'solicitud_chat',
            mensaje: $mensaje,
            disponibleHasta: $solicitud->expires_at,
            metadata: [
                'solicitud_id' => $solicitud->id_chat_solicitud,
                'chat_id' => $solicitud->id_chat,
                'solicitante_id' => $solicitud->id_solicitante,
                'solicitante_nombre' => $nombre,
            ]
        );
    }

    public function notificarInvitacionGrupo(ChatInvitacion $invitacion): Notificacion
    {
        $invitacion->loadMissing(['invitador', 'invitado', 'chat']);

        $nombreInvitador = $this->nombreUsuario($invitacion->invitador);
        $nombreGrupo = trim((string) ($invitacion->chat?->nombre ?? 'Grupo'));
        $mensaje = $nombreInvitador . ' te invito al grupo ' . $nombreGrupo . '.';

        return $this->crearAccionPersonal(
            actorId: $invitacion->id_invitador,
            destinatarioId: $invitacion->id_invitado,
            contextoTipo: 'chat_invitacion',
            contextoReferencia: (string) $invitacion->id_chat_invitacion,
            tipo: 'invitacion_grupo',
            mensaje: $mensaje,
            disponibleHasta: $invitacion->expires_at,
            metadata: [
                'invitacion_id' => $invitacion->id_chat_invitacion,
                'chat_id' => $invitacion->id_chat,
                'grupo_nombre' => $nombreGrupo,
                'invitador_id' => $invitacion->id_invitador,
                'invitador_nombre' => $nombreInvitador,
            ]
        );
    }

    public function marcarAccionRespondida(
        string $contextoTipo,
        int $contextoReferencia,
        int $respondedorId,
        string $estado,
        ?string $respuesta = null
    ): void {
        Notificacion::query()
            ->where('modulo', self::MODULO_PERSONALES)
            ->where('contexto_tipo', $contextoTipo)
            ->where('contexto_referencia', (string) $contextoReferencia)
            ->update([
                'accion_estado' => $estado,
                'accion_respuesta' => $respuesta,
                'accion_respuesta_usuario_id' => $respondedorId,
                'accion_respondida_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function crearAccionPersonal(
        ?int $actorId,
        int $destinatarioId,
        string $contextoTipo,
        string $contextoReferencia,
        string $tipo,
        string $mensaje,
        mixed $disponibleHasta,
        array $metadata
    ): Notificacion {
        return DB::transaction(function () use (
            $actorId,
            $destinatarioId,
            $contextoTipo,
            $contextoReferencia,
            $tipo,
            $mensaje,
            $disponibleHasta,
            $metadata
        ): Notificacion {
            $notificacion = Notificacion::updateOrCreate(
                [
                    'modulo' => self::MODULO_PERSONALES,
                    'contexto_tipo' => $contextoTipo,
                    'contexto_referencia' => $contextoReferencia,
                    'tipo' => $tipo,
                ],
                [
                    'id_usuario_actor' => $actorId,
                    'grupo_titulo' => 'Mensajeria',
                    'mensaje' => $mensaje,
                    'accion_estado' => 'pendiente',
                    'accion_respuesta' => null,
                    'accion_respuesta_usuario_id' => null,
                    'accion_respondida_at' => null,
                    'accion_disponible_nuevamente_at' => $disponibleHasta,
                    'metadata' => $metadata,
                ]
            );

            NotificacionUsuario::firstOrCreate(
                [
                    'id_notificacion' => $notificacion->id_notificacion,
                    'id_usuario' => $destinatarioId,
                ],
                [
                    'leido_en' => null,
                ]
            );

            return $notificacion->fresh();
        });
    }

    private function nombreUsuario(?Usuario $usuario): string
    {
        if (! $usuario) {
            return 'Un usuario';
        }

        $nombre = trim(($usuario->nombre ?? '') . ' ' . ($usuario->apellido ?? ''));

        return $nombre !== '' ? $nombre : ($usuario->correo ?? 'Un usuario');
    }
}
