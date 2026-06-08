<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\EventoInscripcionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventoInscripcionController extends Controller
{
    public function __construct(
        private readonly EventoInscripcionService $service
    ) {
    }

    /**
     * Obtiene los eventos visibles para el home del usuario
     */
    public function index(Request $request, int $userId): JsonResponse
    {
        /*if ($response = $this->rejectOtherUser($request, $userId)) {
            return $response;
        }*/

        $perPage = (int) $request->query('por_pagina', 12);

        $eventos = $this->service->ver($userId, $perPage);

        return response()->json([
            'accion' => true,
            'mensaje' => 'Eventos obtenidos correctamente',
            'eventos' => $eventos->items(),
            'paginacion' => [
                'pagina_actual' => $eventos->currentPage(),
                'por_pagina' => $eventos->perPage(),
                'total' => $eventos->total(),
                'ultima_pagina' => $eventos->lastPage(),
                'desde' => $eventos->firstItem(),
                'hasta' => $eventos->lastItem(),
            ],
        ]);
    }

    public function publicos(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('por_pagina', 12);
        $eventos = $this->service->verPublicos($perPage);

        return response()->json([
            'accion' => true,
            'mensaje' => 'Eventos publicos obtenidos correctamente',
            'eventos' => $eventos->items(),
            'paginacion' => [
                'pagina_actual' => $eventos->currentPage(),
                'por_pagina' => $eventos->perPage(),
                'total' => $eventos->total(),
                'ultima_pagina' => $eventos->lastPage(),
                'desde' => $eventos->firstItem(),
                'hasta' => $eventos->lastItem(),
            ],
        ]);
    }

    /**
     * Inscribe al usuario en un evento
     */
    public function inscribirse(Request $request, int $userId, int $eventoId): JsonResponse
    {
        /*if ($response = $this->rejectOtherUser($request, $userId)) {
            return $response;
        }*/

        $response = $this->service->inscribirse($userId, $eventoId);

        return response()->json(
            $response,
            $this->getStatusCode($response)
        );
    }

    /**
     * Desinscribe al usuario de un evento
     */
    public function desinscribirse(Request $request, int $userId, int $eventoId): JsonResponse
    {
       /* if ($response = $this->rejectOtherUser($request, $userId)) {
            return $response;
        }*/

        $response = $this->service->desinscribirse($userId, $eventoId);

        return response()->json(
            $response,
            $this->getStatusCode($response)
        );
    }

    /**
     * Rechaza acceso si el usuario intenta operar sobre otro usuario
     */
    private function rejectOtherUser(Request $request, int $userId): ?JsonResponse
    {
        if ((int) $request->user()?->id_usuario === $userId) {
            return null;
        }

        return response()->json([
            'accion' => false,
            'mensaje' => 'No autorizado',
            'datos' => null,
        ], 403);
    }


    private function getStatusCode(array $response): int
    {
        if (($response['accion'] ?? false) === true) {
            return 200;
        }

        return match ($response['mensaje'] ?? '') {
            'Evento no encontrado' => 404,
            'El usuario ya está inscrito en este evento' => 409,
            'El evento ya no tiene cupos disponibles' => 409,
            'El usuario no tiene una inscripción activa en este evento' => 404,
            'El usuario no cumple la segmentación del evento' => 403,
            default => 422,
        };
    }
}
