<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Models\AdminEvento;
use App\Models\SolicitudPublicante;
use App\Services\api\AdminEventoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventoController extends Controller
{
    public function __construct(
        private readonly AdminEventoService $eventoService
    ) {
    }

    public function workspace(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        return response()->json($this->eventoService->workspace());
    }

    public function index(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        return response()->json([
            'data' => $this->eventoService->adminEvents(),
        ]);
    }

    public function publisherRequests(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        return response()->json([
            'data' => $this->eventoService->publisherRequests(),
        ]);
    }

    public function approvePublisherRequest(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $data = $request->validate([
            'motivo' => ['required_without:reason', 'string', 'min:5', 'max:1000'],
            'reason' => ['required_without:motivo', 'string', 'min:5', 'max:1000'],
        ]);
        $publisherRequest = SolicitudPublicante::query()
            ->with([
                'usuario:id_usuario,nombre,apellido,correo,telefono,rol',
                'usuario.perfil:id_perfil,usuario_id,foto_perfil',
            ])
            ->find($id);

        if (! $publisherRequest) {
            return response()->json(['message' => 'Solicitud no encontrada.'], 404);
        }

        try {
            $publisherRequest = $this->eventoService->approvePublisherRequest(
                $publisherRequest,
                $request->user(),
                $data['motivo'] ?? $data['reason']
            );
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Solicitud aprobada correctamente.',
            'data' => $this->eventoService->formatPublisherRequest($publisherRequest),
        ]);
    }

    public function rejectPublisherRequest(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $data = $request->validate([
            'motivo' => ['required_without:reason', 'string', 'min:5', 'max:1000'],
            'reason' => ['required_without:motivo', 'string', 'min:5', 'max:1000'],
        ]);
        $publisherRequest = SolicitudPublicante::query()
            ->with([
                'usuario:id_usuario,nombre,apellido,correo,telefono,rol',
                'usuario.perfil:id_perfil,usuario_id,foto_perfil',
            ])
            ->find($id);

        if (! $publisherRequest) {
            return response()->json(['message' => 'Solicitud no encontrada.'], 404);
        }

        try {
            $publisherRequest = $this->eventoService->rejectPublisherRequest(
                $publisherRequest,
                $request->user(),
                $data['motivo'] ?? $data['reason']
            );
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Solicitud rechazada correctamente.',
            'data' => $this->eventoService->formatPublisherRequest($publisherRequest),
        ]);
    }

    public function eventAction(Request $request, int $id, string $action): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        if (! in_array($action, ['activar', 'pausar', 'suspender', 'eliminar'], true)) {
            return response()->json(['message' => 'Accion administrativa invalida.'], 404);
        }

        $data = $request->validate([
            'motivo' => ['required_without:reason', 'string', 'min:5', 'max:1000'],
            'reason' => ['required_without:motivo', 'string', 'min:5', 'max:1000'],
        ]);
        $event = AdminEvento::query()
            ->with('creador:id_usuario,nombre,apellido,correo,telefono,rol')
            ->find($id);

        if (! $event) {
            return response()->json(['message' => 'Evento no encontrado.'], 404);
        }

        try {
            $event = $this->eventoService->applyAdminEventAction(
                $event,
                $request->user(),
                $action,
                $data['motivo'] ?? $data['reason']
            );
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Accion administrativa aplicada correctamente.',
            'data' => $this->eventoService->formatEvent($event),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->eventAction($request, $id, 'eliminar');
    }

    private function forbidNonAdmin(Request $request): ?JsonResponse
    {
        if ($request->user()?->rol === 'admin') {
            return null;
        }

        return response()->json([
            'message' => 'No tienes permiso para acceder a la administracion de eventos.',
        ], 403);
    }
}
