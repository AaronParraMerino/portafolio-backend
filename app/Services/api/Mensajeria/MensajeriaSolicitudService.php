<?php

namespace App\Services\api\Mensajeria;

use App\Models\Chat;
use App\Models\ChatMensaje;
use App\Models\ChatPrivadoPar;
use App\Models\ChatSolicitud;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class MensajeriaSolicitudService
{
    public function __construct(
        private readonly MensajeriaHelperService $helper,
        private readonly MensajeriaNotificacionService $notificaciones,
    ) {
    }

    public function estadoContactoPerfil(int $idUsuarioActual, int $idUsuarioObjetivo): array
    {
        if ($idUsuarioActual === $idUsuarioObjetivo) {
            return $this->helper->success([
                'estado' => 'propio_perfil',
                'boton' => null,
            ]);
        }

        $chat = $this->helper->buscarChatPrivado($idUsuarioActual, $idUsuarioObjetivo);

        if (! $chat) {
            return $this->helper->success([
                'estado' => 'sin_relacion',
                'boton' => 'Contactarme por la aplicacion',
            ]);
        }

        $participantes = $chat->participantes->keyBy('id_usuario');
        $yo = $participantes->get($idUsuarioActual);
        $otro = $participantes->get($idUsuarioObjetivo);

        if ($otro?->bloqueo_saliente) {
            return $this->helper->success([
                'estado' => 'bloqueado_por_otro',
                'boton' => null,
                'id_chat' => $chat->id_chat,
            ]);
        }

        if ($yo?->bloqueo_saliente) {
            return $this->helper->success([
                'estado' => 'bloqueado_por_mi',
                'boton' => 'Restaurar interacciones',
                'id_chat' => $chat->id_chat,
            ]);
        }

        if ($chat->estado === 'activo') {
            return $this->helper->success([
                'estado' => 'chat_activo',
                'boton' => 'Ir al chat',
                'id_chat' => $chat->id_chat,
            ]);
        }

        $solicitud = $chat->solicitudes()
            ->where('estado', 'pendiente')
            ->latest('id_chat_solicitud')
            ->first();

        if ($solicitud) {
            return $this->helper->success([
                'estado' => $solicitud->id_solicitante === $idUsuarioActual
                    ? 'solicitud_enviada'
                    : 'solicitud_recibida',
                'boton' => $solicitud->id_solicitante === $idUsuarioActual
                    ? 'Solicitud enviada'
                    : 'Responder solicitud',
                'id_chat' => $chat->id_chat,
                'id_chat_solicitud' => $solicitud->id_chat_solicitud,
                'expires_at' => optional($solicitud->expires_at)->toISOString(),
            ]);
        }

        $cooldown = $chat->solicitudes()
            ->where('id_solicitante', $idUsuarioActual)
            ->where('cooldown_until', '>', now())
            ->latest('cooldown_until')
            ->first();

        if ($cooldown) {
            return $this->helper->success([
                'estado' => 'cooldown',
                'boton' => 'Solicitud enviada',
                'id_chat' => $chat->id_chat,
                'disponible_desde' => optional($cooldown->cooldown_until)->toISOString(),
            ]);
        }

        return $this->helper->success([
            'estado' => 'sin_relacion',
            'boton' => 'Contactarme por la aplicacion',
            'id_chat' => $chat->id_chat,
        ]);
    }

    public function crearSolicitudPrivada(int $idSolicitante, int $idDestinatario, string $mensajeInicial): array
    {
        $mensajeInicial = trim($mensajeInicial);

        if ($idSolicitante === $idDestinatario) {
            return $this->helper->error('invalid_payload', 'No puedes iniciar un chat contigo mismo.');
        }

        if ($mensajeInicial === '') {
            return $this->helper->error('invalid_payload', 'El mensaje inicial es obligatorio.');
        }

        if (! Usuario::where('id_usuario', $idDestinatario)->exists()) {
            return $this->helper->error('not_found', 'Usuario destinatario no encontrado.');
        }

        try {
            return DB::transaction(function () use ($idSolicitante, $idDestinatario, $mensajeInicial): array {
                $chat = $this->helper->buscarChatPrivado($idSolicitante, $idDestinatario, true);

                if ($chat) {
                    if ($this->helper->estadoBloqueoPrivado($chat, $idSolicitante) !== null) {
                        return $this->helper->error('blocked', 'No se puede iniciar la solicitud por bloqueo privado.');
                    }

                    if ($chat->estado === 'activo') {
                        return $this->helper->success([
                            'id_chat' => $chat->id_chat,
                            'estado' => 'chat_existente',
                        ], 'Ya existe un chat activo.');
                    }

                    $pendiente = $chat->solicitudes()
                        ->where('estado', 'pendiente')
                        ->lockForUpdate()
                        ->first();

                    if ($pendiente) {
                        return $this->helper->error('already_pending', 'Ya existe una solicitud pendiente.');
                    }

                    $cooldown = $chat->solicitudes()
                        ->where('id_solicitante', $idSolicitante)
                        ->where('cooldown_until', '>', now())
                        ->lockForUpdate()
                        ->latest('cooldown_until')
                        ->first();

                    if ($cooldown) {
                        return $this->helper->error('cooldown', 'Debes esperar antes de enviar otra solicitud.', [
                            'cooldown_until' => optional($cooldown->cooldown_until)->toISOString(),
                        ]);
                    }
                } else {
                    $chat = Chat::create([
                        'tipo' => 'privado',
                        'estado' => 'solicitud',
                        'id_usuario_creador' => $idSolicitante,
                    ]);

                    [$menor, $mayor] = $this->helper->ordenarParUsuarios($idSolicitante, $idDestinatario);

                    ChatPrivadoPar::create([
                        'id_chat' => $chat->id_chat,
                        'id_usuario_menor' => $menor,
                        'id_usuario_mayor' => $mayor,
                    ]);
                }

                $chat->update(['estado' => 'solicitud']);
                $this->helper->asegurarParticipantePrivado($chat->id_chat, $idSolicitante, 'solicitante', 'activo');
                $this->helper->asegurarParticipantePrivado($chat->id_chat, $idDestinatario, 'miembro', 'pendiente');

                $solicitud = ChatSolicitud::create([
                    'id_chat' => $chat->id_chat,
                    'id_solicitante' => $idSolicitante,
                    'id_destinatario' => $idDestinatario,
                    'estado' => 'pendiente',
                    'mensaje_inicial' => $mensajeInicial,
                    'expires_at' => now()->addDays(MensajeriaHelperService::SOLICITUD_DIAS),
                ]);

                $this->notificaciones->notificarSolicitudChat($solicitud);

                return $this->helper->success([
                    'chat' => $this->helper->serializarChatBasico($chat->fresh()),
                    'solicitud' => $this->helper->serializarSolicitud($solicitud),
                ], 'Solicitud enviada correctamente.');
            });
        } catch (\Throwable $e) {
            return $this->helper->error('server_error', 'No se pudo crear la solicitud.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function aceptarSolicitud(int $idUsuario, int $idSolicitud): array
    {
        return $this->responderSolicitud($idUsuario, $idSolicitud, 'aceptada');
    }

    public function rechazarSolicitud(int $idUsuario, int $idSolicitud): array
    {
        return $this->responderSolicitud($idUsuario, $idSolicitud, 'rechazada');
    }

    private function responderSolicitud(int $idUsuario, int $idSolicitud, string $respuesta): array
    {
        try {
            return DB::transaction(function () use ($idUsuario, $idSolicitud, $respuesta): array {
                $solicitud = ChatSolicitud::with('chat.participantes')
                    ->lockForUpdate()
                    ->find($idSolicitud);

                if (! $solicitud || $solicitud->id_destinatario !== $idUsuario) {
                    return $this->helper->error('not_found', 'Solicitud no encontrada.');
                }

                if ($solicitud->estado !== 'pendiente') {
                    return $this->helper->error('invalid_state', 'La solicitud ya fue respondida.');
                }

                if ($solicitud->expires_at && $solicitud->expires_at->isPast()) {
                    $solicitud->update([
                        'estado' => 'expirada',
                        'cooldown_until' => now()->addDays(MensajeriaHelperService::COOLDOWN_DIAS),
                    ]);
                    $this->notificaciones->marcarAccionRespondida('chat_solicitud', $solicitud->id_chat_solicitud, $idUsuario, 'expirada');
                    return $this->helper->error('expired', 'La solicitud expiro.');
                }

                if ($respuesta === 'rechazada') {
                    $solicitud->update([
                        'estado' => 'rechazada',
                        'cooldown_until' => now()->addDays(MensajeriaHelperService::COOLDOWN_DIAS),
                    ]);
                    $solicitud->chat->update(['estado' => 'cerrado']);
                    $this->notificaciones->marcarAccionRespondida('chat_solicitud', $solicitud->id_chat_solicitud, $idUsuario, 'rechazada');

                    return $this->helper->success([
                        'solicitud' => $this->helper->serializarSolicitud($solicitud->fresh()),
                    ], 'Solicitud rechazada.');
                }

                $chat = $solicitud->chat;
                $chat->update(['estado' => 'activo']);
                $solicitud->update(['estado' => 'aceptada']);

                $this->helper->asegurarParticipantePrivado($chat->id_chat, $idUsuario, 'miembro', 'activo');

                $mensaje = ChatMensaje::create([
                    'id_chat' => $chat->id_chat,
                    'id_usuario_emisor' => $solicitud->id_solicitante,
                    'tipo' => 'texto',
                    'contenido' => $solicitud->mensaje_inicial,
                ]);

                $this->helper->marcarLeidoHasta($chat->id_chat, $solicitud->id_solicitante, $mensaje->id_chat_mensaje);
                $this->notificaciones->marcarAccionRespondida('chat_solicitud', $solicitud->id_chat_solicitud, $idUsuario, 'aceptada');

                return $this->helper->success([
                    'chat' => $this->helper->serializarChatBasico($chat->fresh()),
                    'mensaje_inicial' => $this->helper->serializarMensaje($mensaje),
                ], 'Solicitud aceptada.');
            });
        } catch (\Throwable $e) {
            return $this->helper->error('server_error', 'No se pudo responder la solicitud.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
