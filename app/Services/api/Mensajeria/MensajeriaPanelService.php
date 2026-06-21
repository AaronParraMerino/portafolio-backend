<?php

namespace App\Services\api\Mensajeria;

use App\Models\ChatParticipante;
use App\Models\ChatInvitacion;
use App\Models\ChatSolicitud;

class MensajeriaPanelService
{
    public function __construct(
        private readonly MensajeriaHelperService $helper
    ) {
    }

    public function listarPanel(int $idUsuario): array
    {
        $participaciones = ChatParticipante::query()
            ->with(['chat.participantes.usuario'])
            ->where('id_usuario', $idUsuario)
            ->whereIn('estado', ['activo', 'pendiente'])
            ->get();

        $privados = [];
        $grupos = [];
        foreach ($participaciones as $participacion) {
            $chat = $participacion->chat;
            if (! $chat || $chat->trashed() || $chat->estado !== 'activo') {
                continue;
            }

            $item = $this->helper->serializarResumenChat($chat, $idUsuario, $participacion);
            if ($chat->tipo === 'privado') {
                $privados[] = $item;
            } else {
                $grupos[] = $item;
            }
        }

        $solicitudes = ChatSolicitud::query()
            ->with(['chat', 'solicitante', 'destinatario'])
            ->where(function ($query) use ($idUsuario) {
                $query
                    ->where('id_solicitante', $idUsuario)
                    ->orWhere('id_destinatario', $idUsuario);
            })
            ->where('estado', 'pendiente')
            ->latest('id_chat_solicitud')
            ->get()
            ->map(fn (ChatSolicitud $solicitud) => $this->helper->serializarSolicitud($solicitud))
            ->values()
            ->all();

        $invitaciones = ChatInvitacion::query()
            ->with(['chat', 'invitador'])
            ->where('id_invitado', $idUsuario)
            ->where('estado', 'pendiente')
            ->latest('id_chat_invitacion')
            ->get()
            ->map(fn (ChatInvitacion $invitacion) => $this->helper->serializarInvitacion($invitacion))
            ->values()
            ->all();

        return $this->helper->success([
            'privados' => $privados,
            'grupos' => $grupos,
            'solicitudes' => $solicitudes,
            'invitaciones' => $invitaciones,
            'contador_novedades' => $this->helper->contarChatsConNovedades($idUsuario),
        ]);
    }
}
