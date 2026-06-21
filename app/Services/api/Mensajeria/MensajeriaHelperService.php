<?php

namespace App\Services\api\Mensajeria;

use App\Models\Chat;
use App\Models\ChatInvitacion;
use App\Models\ChatLectura;
use App\Models\ChatMensaje;
use App\Models\ChatParticipante;
use App\Models\ChatPrivadoPar;
use App\Models\ChatSolicitud;
use Illuminate\Support\Facades\DB;

class MensajeriaHelperService
{
    public const SOLICITUD_DIAS = 7;
    public const COOLDOWN_DIAS = 30;
    public const PAGE_SIZE = 5;

    public function buscarChatPrivado(int $idUsuarioA, int $idUsuarioB, bool $lock = false): ?Chat
    {
        [$menor, $mayor] = $this->ordenarParUsuarios($idUsuarioA, $idUsuarioB);

        $query = ChatPrivadoPar::query()
            ->with(['chat.participantes.usuario', 'chat.solicitudes'])
            ->where('id_usuario_menor', $menor)
            ->where('id_usuario_mayor', $mayor);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first()?->chat;
    }

    public function existeChatPrivadoActivo(int $idUsuarioA, int $idUsuarioB): bool
    {
        $chat = $this->buscarChatPrivado($idUsuarioA, $idUsuarioB);

        return $chat
            && $chat->estado === 'activo'
            && ! $chat->participantes->contains(fn ($p) => (bool) $p->bloqueo_saliente);
    }

    public function estadoBloqueoPrivado(Chat $chat, int $idUsuario): ?string
    {
        $participantes = $chat->participantes->keyBy('id_usuario');
        $yo = $participantes->get($idUsuario);
        $otro = $participantes->first(fn ($p) => (int) $p->id_usuario !== $idUsuario);

        if ($yo?->bloqueo_saliente) {
            return 'bloqueado_por_mi';
        }

        if ($otro?->bloqueo_saliente) {
            return 'bloqueado_por_otro';
        }

        return null;
    }

    public function crearParticipante(int $idChat, int $idUsuario, string $rol, string $estado): ChatParticipante
    {
        return ChatParticipante::create([
            'id_chat' => $idChat,
            'id_usuario' => $idUsuario,
            'rol' => $rol,
            'estado' => $estado,
            'joined_at' => $estado === 'activo' ? now() : null,
        ]);
    }

    public function asegurarParticipantePrivado(
        int $idChat,
        int $idUsuario,
        string $rol,
        string $estado
    ): ChatParticipante {
        $participante = ChatParticipante::query()
            ->where('id_chat', $idChat)
            ->where('id_usuario', $idUsuario)
            ->first();

        if (! $participante) {
            return $this->crearParticipante($idChat, $idUsuario, $rol, $estado);
        }

        $participante->update([
            'rol' => $rol,
            'estado' => $estado,
            'archivado' => DB::raw('false'),
            'left_at' => null,
            'joined_at' => $estado === 'activo'
                ? ($participante->joined_at ?? now())
                : null,
        ]);

        return $participante->fresh();
    }

    public function participacionActual(?Chat $chat, int $idUsuario): ?ChatParticipante
    {
        if (! $chat) {
            return null;
        }

        return $chat->participantes
            ->where('id_usuario', $idUsuario)
            ->whereIn('estado', ['activo', 'salio'])
            ->sortByDesc('id_chat_participante')
            ->first();
    }

    public function marcarLeidoHasta(int $idChat, int $idUsuario, int $idMensaje): void
    {
        $lectura = ChatLectura::firstOrCreate(
            [
                'id_chat' => $idChat,
                'id_usuario' => $idUsuario,
            ],
            [
                'ultimo_mensaje_leido_id' => $idMensaje,
                'leido_at' => now(),
            ]
        );

        if ((int) ($lectura->ultimo_mensaje_leido_id ?? 0) < $idMensaje) {
            $lectura->update([
                'ultimo_mensaje_leido_id' => $idMensaje,
                'leido_at' => now(),
            ]);
        }
    }

    public function validarPuedeEscribir(?Chat $chat, int $idUsuario): ?array
    {
        if (! $chat || $chat->trashed() || $chat->estado !== 'activo') {
            return $this->error('not_found', 'Chat no encontrado o no activo.');
        }

        $participante = $chat->participantes
            ->where('id_usuario', $idUsuario)
            ->where('estado', 'activo')
            ->first();

        if (! $participante) {
            return $this->error('forbidden', 'No eres participante activo del chat.');
        }

        if ($chat->tipo === 'privado' && $chat->participantes->contains(fn ($p) => (bool) $p->bloqueo_saliente)) {
            return $this->error('blocked', 'El chat privado esta bloqueado.');
        }

        if ($chat->tipo === 'grupo' && $chat->participantes->where('estado', 'activo')->count() < 2) {
            return $this->error('invalid_state', 'El grupo necesita al menos dos miembros activos para enviar mensajes.');
        }

        return null;
    }

    public function contarChatsConNovedades(int $idUsuario): int
    {
        return ChatParticipante::query()
            ->join('chats as c', 'c.id_chat', '=', 'chat_participantes.id_chat')
            ->leftJoin('chat_lecturas as cl', function ($join) use ($idUsuario) {
                $join->on('cl.id_chat', '=', 'chat_participantes.id_chat')
                    ->where('cl.id_usuario', '=', $idUsuario);
            })
            ->where('chat_participantes.id_usuario', $idUsuario)
            ->where('chat_participantes.estado', 'activo')
            ->where('c.estado', 'activo')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('chat_mensajes as cm')
                    ->whereColumn('cm.id_chat', 'chat_participantes.id_chat')
                    ->whereNull('cm.deleted_at')
                    ->whereColumn('cm.id_chat_mensaje', '>', DB::raw('COALESCE(cl.ultimo_mensaje_leido_id, 0)'));
            })
            ->count();
    }

    public function puedeGestionarGrupo(Chat $chat, int $idUsuario): bool
    {
        return $chat->participantes
            ->where('id_usuario', $idUsuario)
            ->where('estado', 'activo')
            ->whereIn('rol', ['owner', 'admin'])
            ->isNotEmpty();
    }

    public function garantizarOwnerGrupo(Chat $chat): void
    {
        if ($chat->participantes->where('estado', 'activo')->where('rol', 'owner')->isNotEmpty()) {
            return;
        }

        $nuevoOwner = $chat->participantes
            ->where('estado', 'activo')
            ->where('rol', 'admin')
            ->sortBy('joined_at')
            ->first()
            ?? $chat->participantes
                ->where('estado', 'activo')
                ->sortBy('joined_at')
                ->first();

        if ($nuevoOwner) {
            $nuevoOwner->update(['rol' => 'owner']);
        }
    }

    public function ordenarParUsuarios(int $idUsuarioA, int $idUsuarioB): array
    {
        return $idUsuarioA < $idUsuarioB
            ? [$idUsuarioA, $idUsuarioB]
            : [$idUsuarioB, $idUsuarioA];
    }

    public function serializarResumenChat(Chat $chat, int $idUsuario, ChatParticipante $participacion): array
    {
        $otroParticipante = $chat->tipo === 'privado'
            ? $this->otroParticipantePrivado($chat, $idUsuario)
            : null;
        $ultimoMensaje = ChatMensaje::query()
            ->where('id_chat', $chat->id_chat)
            ->whereNull('deleted_at')
            ->latest('id_chat_mensaje')
            ->first();

        return [
            'id_chat' => (int) $chat->id_chat,
            'tipo' => $chat->tipo,
            'nombre' => $chat->tipo === 'grupo'
                ? $chat->nombre
                : $this->nombreParticipante($otroParticipante),
            'id_otro_usuario' => $otroParticipante?->id_usuario ? (int) $otroParticipante->id_usuario : null,
            'rol' => $participacion->rol,
            'miembros_activos' => $chat->tipo === 'grupo'
                ? $chat->participantes->where('estado', 'activo')->count()
                : null,
            'archivado' => (bool) $participacion->archivado,
            'bloqueado_por_mi' => (bool) $participacion->bloqueo_saliente,
            'ultimo_mensaje' => $ultimoMensaje ? $this->serializarMensaje($ultimoMensaje) : null,
        ];
    }

    public function serializarChatBasico(Chat $chat): array
    {
        return [
            'id_chat' => (int) $chat->id_chat,
            'tipo' => $chat->tipo,
            'estado' => $chat->estado,
            'nombre' => $chat->nombre,
            'created_at' => optional($chat->created_at)->toISOString(),
        ];
    }

    public function serializarParticipante(ChatParticipante $participante): array
    {
        return [
            'id_chat_participante' => (int) $participante->id_chat_participante,
            'id_chat' => (int) $participante->id_chat,
            'id_usuario' => (int) $participante->id_usuario,
            'rol' => $participante->rol,
            'estado' => $participante->estado,
            'joined_at' => optional($participante->joined_at)->toISOString(),
            'left_at' => optional($participante->left_at)->toISOString(),
        ];
    }

    public function serializarSolicitud(ChatSolicitud $solicitud): array
    {
        return [
            'id_chat_solicitud' => (int) $solicitud->id_chat_solicitud,
            'id_chat' => (int) $solicitud->id_chat,
            'id_solicitante' => (int) $solicitud->id_solicitante,
            'id_destinatario' => (int) $solicitud->id_destinatario,
            'estado' => $solicitud->estado,
            'mensaje_inicial' => $solicitud->mensaje_inicial,
            'expires_at' => optional($solicitud->expires_at)->toISOString(),
            'cooldown_until' => optional($solicitud->cooldown_until)->toISOString(),
        ];
    }

    public function serializarInvitacion(ChatInvitacion $invitacion): array
    {
        $chat = $invitacion->chat;
        $invitador = $invitacion->invitador;
        $nombreInvitador = trim(($invitador->nombre ?? '') . ' ' . ($invitador->apellido ?? ''));

        return [
            'id_chat_invitacion' => (int) $invitacion->id_chat_invitacion,
            'id_chat' => (int) $invitacion->id_chat,
            'id_invitador' => $invitacion->id_invitador ? (int) $invitacion->id_invitador : null,
            'id_invitado' => (int) $invitacion->id_invitado,
            'grupo_nombre' => $chat?->nombre ?? 'Grupo',
            'invitador_nombre' => $nombreInvitador !== '' ? $nombreInvitador : ($invitador->correo ?? 'Usuario'),
            'estado' => $invitacion->estado,
            'expires_at' => optional($invitacion->expires_at)->toISOString(),
            'respondida_at' => optional($invitacion->respondida_at)->toISOString(),
        ];
    }

    public function serializarMensaje(ChatMensaje $mensaje): array
    {
        return [
            'id_chat_mensaje' => (int) $mensaje->id_chat_mensaje,
            'id_chat' => (int) $mensaje->id_chat,
            'id_usuario_emisor' => $mensaje->id_usuario_emisor ? (int) $mensaje->id_usuario_emisor : null,
            'emisor_nombre' => $this->nombreUsuario($mensaje->emisor),
            'tipo' => $mensaje->tipo,
            'contenido' => $mensaje->contenido,
            'metadata' => $mensaje->metadata ?? [],
            'created_at' => optional($mensaje->created_at)->toISOString(),
        ];
    }

    public function success(array $data = [], string $message = 'OK'): array
    {
        return [
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ];
    }

    public function error(string $status, string $message, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'message' => $message,
        ], $extra);
    }

    private function otroParticipantePrivado(Chat $chat, int $idUsuario): ?ChatParticipante
    {
        return $chat->participantes->first(fn ($p) => (int) $p->id_usuario !== $idUsuario);
    }

    private function nombreParticipante(?ChatParticipante $participante): string
    {
        $usuario = $participante?->usuario;
        return $this->nombreUsuario($usuario, 'Chat privado');
    }

    private function nombreUsuario($usuario, string $fallback = 'Usuario'): string
    {
        $nombre = trim(($usuario->nombre ?? '') . ' ' . ($usuario->apellido ?? ''));

        return $nombre !== '' ? $nombre : ($usuario->correo ?? $fallback);
    }
}
