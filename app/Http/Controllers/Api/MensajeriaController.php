<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\Mensajeria\MensajeriaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MensajeriaController extends Controller
{
    public function __construct(
        private readonly MensajeriaService $service
    ) {
    }

    public function panel(Request $request): JsonResponse
    {
        return $this->json($this->service->listarPanel($this->idUsuario($request)));
    }

    public function estadoContacto(Request $request, int $usuarioId): JsonResponse
    {
        return $this->json($this->service->estadoContactoPerfil($this->idUsuario($request), $usuarioId));
    }

    public function crearSolicitud(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_destinatario' => ['required', 'integer', 'min:1'],
            'mensaje_inicial' => ['required', 'string', 'max:1000'],
        ]);

        return $this->json($this->service->crearSolicitudPrivada(
            $this->idUsuario($request),
            (int) $data['id_destinatario'],
            $data['mensaje_inicial']
        ));
    }

    public function aceptarSolicitud(Request $request, int $solicitudId): JsonResponse
    {
        return $this->json($this->service->aceptarSolicitud($this->idUsuario($request), $solicitudId));
    }

    public function rechazarSolicitud(Request $request, int $solicitudId): JsonResponse
    {
        return $this->json($this->service->rechazarSolicitud($this->idUsuario($request), $solicitudId));
    }

    public function crearGrupo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'invitados' => ['sometimes', 'array'],
            'invitados.*' => ['integer', 'min:1'],
        ]);

        return $this->json($this->service->crearGrupo(
            $this->idUsuario($request),
            $data['nombre'],
            $data['invitados'] ?? []
        ), 201);
    }

    public function invitarGrupo(Request $request, int $chatId): JsonResponse
    {
        $data = $request->validate([
            'id_invitado' => ['required', 'integer', 'min:1'],
        ]);

        return $this->json($this->service->invitarAGrupo(
            $this->idUsuario($request),
            $chatId,
            (int) $data['id_invitado']
        ));
    }

    public function aceptarInvitacion(Request $request, int $invitacionId): JsonResponse
    {
        return $this->json($this->service->aceptarInvitacion($this->idUsuario($request), $invitacionId));
    }

    public function rechazarInvitacion(Request $request, int $invitacionId): JsonResponse
    {
        return $this->json($this->service->rechazarInvitacion($this->idUsuario($request), $invitacionId));
    }

    public function enviarMensaje(Request $request, int $chatId): JsonResponse
    {
        $data = $request->validate([
            'contenido' => ['required', 'string', 'max:4000'],
        ]);

        return $this->json($this->service->enviarMensajeTexto(
            $this->idUsuario($request),
            $chatId,
            $data['contenido']
        ), 201);
    }

    public function mensajes(Request $request, int $chatId): JsonResponse
    {
        $data = $request->validate([
            'antes_de' => ['sometimes', 'integer', 'min:1'],
        ]);

        return $this->json($this->service->obtenerMensajes(
            $this->idUsuario($request),
            $chatId,
            isset($data['antes_de']) ? (int) $data['antes_de'] : null
        ));
    }

    public function archivar(Request $request, int $chatId): JsonResponse
    {
        return $this->json($this->service->archivarChat($this->idUsuario($request), $chatId, true));
    }

    public function desarchivar(Request $request, int $chatId): JsonResponse
    {
        return $this->json($this->service->archivarChat($this->idUsuario($request), $chatId, false));
    }

    public function bloquear(Request $request, int $chatId): JsonResponse
    {
        return $this->json($this->service->bloquearPrivado($this->idUsuario($request), $chatId));
    }

    public function desbloquear(Request $request, int $chatId): JsonResponse
    {
        return $this->json($this->service->desbloquearPrivado($this->idUsuario($request), $chatId));
    }

    public function salirGrupo(Request $request, int $chatId): JsonResponse
    {
        return $this->json($this->service->salirGrupo($this->idUsuario($request), $chatId));
    }

    private function idUsuario(Request $request): int
    {
        return (int) $request->user()->id_usuario;
    }

    private function json(array $response, int $successCode = 200): JsonResponse
    {
        return response()->json(
            $response,
            $this->statusCode($response, $successCode)
        );
    }

    private function statusCode(array $response, int $successCode): int
    {
        return match ($response['status'] ?? 'success') {
            'success' => $successCode,
            'not_found' => 404,
            'forbidden' => 403,
            'blocked' => 403,
            'invalid_payload' => 422,
            'invalid_state' => 409,
            'already_pending' => 409,
            'already_member' => 409,
            'cooldown' => 429,
            'expired' => 410,
            'server_error' => 500,
            default => 200,
        };
    }
}
