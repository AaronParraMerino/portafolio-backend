<?php

namespace App\Services\api\Mensajeria;

use App\Models\Chat;
use App\Models\ChatMensaje;
use App\Models\ChatParticipante;
use Illuminate\Support\Facades\DB;

class MensajeriaMensajeService
{
    public function __construct(
        private readonly MensajeriaHelperService $helper
    ) {
    }

    public function enviarMensajeTexto(int $idUsuario, int $idChat, string $contenido): array
    {
        $contenido = trim($contenido);

        if ($contenido === '') {
            return $this->helper->error('invalid_payload', 'El mensaje no puede estar vacio.');
        }

        try {
            return DB::transaction(function () use ($idUsuario, $idChat, $contenido): array {
                $chat = Chat::with('participantes')->lockForUpdate()->find($idChat);
                $validacion = $this->helper->validarPuedeEscribir($chat, $idUsuario);

                if ($validacion !== null) {
                    return $validacion;
                }

                $mensaje = ChatMensaje::create([
                    'id_chat' => $idChat,
                    'id_usuario_emisor' => $idUsuario,
                    'tipo' => 'texto',
                    'contenido' => $contenido,
                ]);
                $mensaje->load('emisor');

                ChatParticipante::query()
                    ->where('id_chat', $idChat)
                    ->where('id_usuario', '!=', $idUsuario)
                    ->where('estado', 'activo')
                    ->update([
                        'archivado' => DB::raw('false'),
                        'updated_at' => now(),
                    ]);

                $this->helper->marcarLeidoHasta($idChat, $idUsuario, $mensaje->id_chat_mensaje);

                return $this->helper->success([
                    'mensaje' => $this->helper->serializarMensaje($mensaje),
                ], 'Mensaje enviado correctamente.');
            });
        } catch (\Throwable $e) {
            return $this->helper->error('server_error', 'No se pudo enviar el mensaje.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function obtenerMensajes(int $idUsuario, int $idChat, ?int $antesDeMensajeId = null): array
    {
        $chat = Chat::with('participantes')->find($idChat);
        $participacion = $this->helper->participacionActual($chat, $idUsuario);

        if (! $chat || ! $participacion) {
            return $this->helper->error('not_found', 'Chat no encontrado.');
        }

        $query = ChatMensaje::query()
            ->with('emisor')
            ->where('id_chat', $idChat)
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $participacion->joined_at ?? $participacion->created_at)
            ->when($participacion->left_at, fn ($q) => $q->where('created_at', '<=', $participacion->left_at))
            ->when($antesDeMensajeId, fn ($q) => $q->where('id_chat_mensaje', '<', $antesDeMensajeId))
            ->orderByDesc('id_chat_mensaje')
            ->limit(MensajeriaHelperService::PAGE_SIZE)
            ->get()
            ->sortBy('id_chat_mensaje')
            ->values();

        $ultimoId = $query->max('id_chat_mensaje');
        if ($ultimoId) {
            $this->helper->marcarLeidoHasta($idChat, $idUsuario, (int) $ultimoId);
        }

        return $this->helper->success([
            'mensajes' => $query->map(fn (ChatMensaje $mensaje) => $this->helper->serializarMensaje($mensaje))->values()->all(),
            'has_more' => $query->count() === MensajeriaHelperService::PAGE_SIZE,
            'page_size' => MensajeriaHelperService::PAGE_SIZE,
        ]);
    }

    public function archivarChat(int $idUsuario, int $idChat, bool $archivado = true): array
    {
        try {
            $participante = ChatParticipante::query()
                ->where('id_chat', $idChat)
                ->where('id_usuario', $idUsuario)
                ->where('estado', 'activo')
                ->first();

            if (! $participante) {
                return $this->helper->error('not_found', 'Chat no encontrado.');
            }

            $participante->update([
                'archivado' => DB::raw($archivado ? 'true' : 'false'),
            ]);

            return $this->helper->success([
                'id_chat' => $idChat,
                'archivado' => $archivado,
            ]);
        } catch (\Throwable $e) {
            return $this->helper->error('server_error', 'No se pudo actualizar el chat.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function bloquearPrivado(int $idUsuario, int $idChat): array
    {
        return $this->actualizarBloqueoPrivado($idUsuario, $idChat, true);
    }

    public function desbloquearPrivado(int $idUsuario, int $idChat): array
    {
        return $this->actualizarBloqueoPrivado($idUsuario, $idChat, false);
    }

    private function actualizarBloqueoPrivado(int $idUsuario, int $idChat, bool $bloqueado): array
    {
        try {
            $chat = Chat::with('participantes')->find($idChat);

            if (! $chat || $chat->tipo !== 'privado') {
                return $this->helper->error('not_found', 'Chat privado no encontrado.');
            }

            $participante = $chat->participantes
                ->where('id_usuario', $idUsuario)
                ->where('estado', 'activo')
                ->first();

            if (! $participante) {
                return $this->helper->error('forbidden', 'No puedes modificar este chat.');
            }

            $participante->update([
                'bloqueo_saliente' => DB::raw($bloqueado ? 'true' : 'false'),
                'archivado' => DB::raw($bloqueado || $participante->archivado ? 'true' : 'false'),
            ]);

            return $this->helper->success([
                'id_chat' => $idChat,
                'bloqueo_saliente' => $bloqueado,
            ], $bloqueado ? 'Chat privado bloqueado.' : 'Interacciones restauradas.');
        } catch (\Throwable $e) {
            return $this->helper->error('server_error', 'No se pudo actualizar el chat.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
