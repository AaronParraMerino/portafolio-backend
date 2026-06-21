<?php

namespace App\Services\api\Mensajeria;

use App\Models\Chat;
use App\Models\ChatInvitacion;
use Illuminate\Support\Facades\DB;

class MensajeriaGrupoService
{
    public function __construct(
        private readonly MensajeriaHelperService $helper,
        private readonly MensajeriaNotificacionService $notificaciones,
    ) {
    }

    public function crearGrupo(int $idOwner, string $nombre, array $invitados = []): array
    {
        $nombre = trim($nombre);

        if ($nombre === '') {
            return $this->helper->error('invalid_payload', 'El nombre del grupo es obligatorio.');
        }

        $invitados = collect($invitados)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && $id !== $idOwner)
            ->unique()
            ->values()
            ->all();

        try {
            return DB::transaction(function () use ($idOwner, $nombre, $invitados): array {
                $chat = Chat::create([
                    'tipo' => 'grupo',
                    'estado' => 'activo',
                    'id_usuario_creador' => $idOwner,
                    'nombre' => $nombre,
                ]);

                $this->helper->crearParticipante($chat->id_chat, $idOwner, 'owner', 'activo');

                $invitaciones = [];
                foreach ($invitados as $idInvitado) {
                    $resultado = $this->crearInvitacionGrupoInterna($chat, $idOwner, $idInvitado);
                    if (($resultado['status'] ?? null) === 'success') {
                        $invitaciones[] = $resultado['data'];
                    }
                }

                return $this->helper->success([
                    'chat' => $this->helper->serializarChatBasico($chat->fresh()),
                    'invitaciones' => $invitaciones,
                ], 'Grupo creado correctamente.');
            });
        } catch (\Throwable $e) {
            return $this->helper->error('server_error', 'No se pudo crear el grupo.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function invitarAGrupo(int $idInvitador, int $idChat, int $idInvitado): array
    {
        $chat = Chat::with('participantes')->find($idChat);

        if (! $chat || $chat->tipo !== 'grupo' || $chat->trashed()) {
            return $this->helper->error('not_found', 'Grupo no encontrado.');
        }

        if (! $this->helper->puedeGestionarGrupo($chat, $idInvitador)) {
            return $this->helper->error('forbidden', 'No tienes permisos para invitar usuarios.');
        }

        try {
            return DB::transaction(fn () => $this->crearInvitacionGrupoInterna($chat, $idInvitador, $idInvitado));
        } catch (\Throwable $e) {
            return $this->helper->error('server_error', 'No se pudo crear la invitacion.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function aceptarInvitacion(int $idUsuario, int $idInvitacion): array
    {
        return $this->responderInvitacion($idUsuario, $idInvitacion, 'aceptada');
    }

    public function rechazarInvitacion(int $idUsuario, int $idInvitacion): array
    {
        return $this->responderInvitacion($idUsuario, $idInvitacion, 'rechazada');
    }

    public function salirGrupo(int $idUsuario, int $idChat): array
    {
        try {
            return DB::transaction(function () use ($idUsuario, $idChat): array {
                $chat = Chat::with('participantes')->lockForUpdate()->find($idChat);

                if (! $chat || $chat->tipo !== 'grupo') {
                    return $this->helper->error('not_found', 'Grupo no encontrado.');
                }

                $participante = $chat->participantes
                    ->where('id_usuario', $idUsuario)
                    ->where('estado', 'activo')
                    ->first();

                if (! $participante) {
                    return $this->helper->error('not_found', 'No eres participante activo del grupo.');
                }

                $participante->update([
                    'estado' => 'salio',
                    'left_at' => now(),
                ]);

                $this->helper->garantizarOwnerGrupo($chat->fresh('participantes'));

                return $this->helper->success([
                    'id_chat' => $idChat,
                    'estado' => 'salio',
                ], 'Saliste del grupo.');
            });
        } catch (\Throwable $e) {
            return $this->helper->error('server_error', 'No se pudo salir del grupo.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function crearInvitacionGrupoInterna(Chat $chat, int $idInvitador, int $idInvitado): array
    {
        if ($idInvitador === $idInvitado) {
            return $this->helper->error('invalid_payload', 'No puedes invitarte a ti mismo.');
        }

        if (! $this->helper->existeChatPrivadoActivo($idInvitador, $idInvitado)) {
            return $this->helper->error('forbidden', 'Solo puedes invitar usuarios con chat privado activo.');
        }

        $activo = $chat->participantes()
            ->where('id_usuario', $idInvitado)
            ->where('estado', 'activo')
            ->exists();

        if ($activo) {
            return $this->helper->error('already_member', 'El usuario ya pertenece al grupo.');
        }

        $pendiente = ChatInvitacion::query()
            ->where('id_chat', $chat->id_chat)
            ->where('id_invitado', $idInvitado)
            ->where('estado', 'pendiente')
            ->first();

        if ($pendiente) {
            return $this->helper->success([
                'invitacion' => $this->helper->serializarInvitacion($pendiente),
            ], 'Ya existe una invitacion pendiente.');
        }

        $invitacion = ChatInvitacion::create([
            'id_chat' => $chat->id_chat,
            'id_invitador' => $idInvitador,
            'id_invitado' => $idInvitado,
            'estado' => 'pendiente',
            'expires_at' => now()->addDays(MensajeriaHelperService::SOLICITUD_DIAS),
        ]);

        $this->notificaciones->notificarInvitacionGrupo($invitacion);

        return $this->helper->success([
            'invitacion' => $this->helper->serializarInvitacion($invitacion),
        ], 'Invitacion enviada.');
    }

    private function responderInvitacion(int $idUsuario, int $idInvitacion, string $respuesta): array
    {
        try {
            return DB::transaction(function () use ($idUsuario, $idInvitacion, $respuesta): array {
                $invitacion = ChatInvitacion::with('chat')
                    ->lockForUpdate()
                    ->find($idInvitacion);

                if (! $invitacion || $invitacion->id_invitado !== $idUsuario) {
                    return $this->helper->error('not_found', 'Invitacion no encontrada.');
                }

                if ($invitacion->estado !== 'pendiente') {
                    return $this->helper->error('invalid_state', 'La invitacion ya fue respondida.');
                }

                if ($invitacion->expires_at && $invitacion->expires_at->isPast()) {
                    $invitacion->update([
                        'estado' => 'expirada',
                        'respondida_at' => now(),
                    ]);
                    $this->notificaciones->marcarAccionRespondida('chat_invitacion', $invitacion->id_chat_invitacion, $idUsuario, 'expirada');
                    return $this->helper->error('expired', 'La invitacion expiro.');
                }

                $invitacion->update([
                    'estado' => $respuesta,
                    'respondida_at' => now(),
                ]);
                $this->notificaciones->marcarAccionRespondida('chat_invitacion', $invitacion->id_chat_invitacion, $idUsuario, $respuesta);

                if ($respuesta === 'rechazada') {
                    return $this->helper->success([
                        'invitacion' => $this->helper->serializarInvitacion($invitacion->fresh()),
                    ], 'Invitacion rechazada.');
                }

                $participante = $this->helper->crearParticipante($invitacion->id_chat, $idUsuario, 'miembro', 'activo');

                return $this->helper->success([
                    'invitacion' => $this->helper->serializarInvitacion($invitacion->fresh()),
                    'participante' => $this->helper->serializarParticipante($participante),
                ], 'Invitacion aceptada.');
            });
        } catch (\Throwable $e) {
            return $this->helper->error('server_error', 'No se pudo responder la invitacion.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
