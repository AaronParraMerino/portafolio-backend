<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\NotificacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificacionService $service
    ) {
    }

    /**
     * Obtiene el resumen de modulos con cantidad de no leidas
     */
    public function modulos(Request $request, int $userId): JsonResponse
    {
        if ($response = $this->rejectOtherUser($request, $userId)) {
            return $response;
        }

        return response()->json(
            $this->service->obtenerResumenModulosNoLeidos($userId)
        );
    }

    /**
     * Obtiene el segundo nivel de un modulo
     */
    public function segundoNivel(Request $request, int $userId, string $modulo): JsonResponse
    {
        if ($response = $this->rejectOtherUser($request, $userId)) {
            return $response;
        }

        $response = $this->service->obtenerSegundoNivelPorModulo($userId, $modulo);

        return response()->json(
            $response,
            $this->getStatusCode($response)
        );
    }

    /**
     * Obtiene los mensajes no leidos de un grupo
     */
    public function mensajesGrupo(
        Request $request,
        int $userId,
        string $modulo,
        string $contextoReferencia
    ): JsonResponse {
        if ($response = $this->rejectOtherUser($request, $userId)) {
            return $response;
        }

        $response = $this->service->obtenerMensajesNoLeidosPorGrupo(
            $userId,
            $modulo,
            $contextoReferencia
        );

        return response()->json(
            $response,
            $this->getStatusCode($response)
        );
    }

    /**
     * Marca una notificacion como leida
     */
    public function markAsRead(Request $request, int $userId, int $notificationId): JsonResponse
    {
        if ($response = $this->rejectOtherUser($request, $userId)) {
            return $response;
        }

        $response = $this->service->marcarNotificacionComoLeida(
            $userId,
            $notificationId
        );

        return response()->json(
            $response,
            $this->getStatusCode($response)
        );
    }

    /**
     * Marca un grupo como leido
     */
    public function markGroupAsRead(Request $request, int $userId): JsonResponse
    {
        if ($response = $this->rejectOtherUser($request, $userId)) {
            return $response;
        }

        $data = $request->validate([
            'modulo' => 'required|string',
            'contexto_referencia' => 'required|string',
        ]);

        $response = $this->service->marcarGrupoComoLeido(
            $userId,
            $data['modulo'],
            $data['contexto_referencia']
        );

        return response()->json(
            $response,
            $this->getStatusCode($response)
        );
    }

    /**
     * Marca un modulo como leido
     */
    public function markModuleAsRead(Request $request, int $userId): JsonResponse
    {
        if ($response = $this->rejectOtherUser($request, $userId)) {
            return $response;
        }

        $data = $request->validate([
            'modulo' => 'required|string',
        ]);

        $response = $this->service->marcarModuloComoLeido(
            $userId,
            $data['modulo']
        );

        return response()->json(
            $response,
            $this->getStatusCode($response)
        );
    }

    /**
     * Marca todas las notificaciones como leidas
     */
    public function markAllAsRead(Request $request, int $userId): JsonResponse
    {
        if ($response = $this->rejectOtherUser($request, $userId)) {
            return $response;
        }

        $response = $this->service->marcarTodasComoLeidas($userId);

        return response()->json(
            $response,
            $this->getStatusCode($response)
        );
    }

    /**
     * Cuenta todas las notificaciones no leidas
     */
    public function countUnread(Request $request, int $userId): JsonResponse
    {
        if ($response = $this->rejectOtherUser($request, $userId)) {
            return $response;
        }

        return response()->json([
            'status' => 'success',
            'pendientes' => $this->service->contarNoLeidas($userId),
        ]);
    }

    /**
     * Rechaza acceso a notificaciones de otro usuario
     */
    private function rejectOtherUser(Request $request, int $userId): ?JsonResponse
    {
        if ((int) $request->user()?->id_usuario === $userId) {
            return null;
        }

        return response()->json([
            'message' => 'No autorizado',
        ], 403);
    }

    /**
     * Obtiene el codigo HTTP segun la respuesta del servicio
     */
    private function getStatusCode(array $response): int
    {
        return match ($response['status'] ?? 'success') {
            'not_found' => 404,
            'invalid_payload' => 422,
            'invalid_module' => 422,
            default => 200,
        };
    }
}