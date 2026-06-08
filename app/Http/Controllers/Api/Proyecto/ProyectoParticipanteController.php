<?php

namespace App\Http\Controllers\Api\Proyecto;

use App\Http\Controllers\Controller;
use App\Services\api\Proyecto\ProyectoParticipanteService;
use App\Services\api\Proyecto\ProyectoPermisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProyectoParticipanteController extends Controller
{
    public function __construct(
        private readonly ProyectoPermisoService $proyectoPermisoService,
        private readonly ProyectoParticipanteService $proyectoParticipanteService,
    ) {}

    public function index(Request $request, int $id): JsonResponse
    {
        $userId = $this->userId($request);

        if (! $this->proyectoPermisoService->userHasAccess($userId, $id)) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id_proyecto' => $id,
                'participantes' => $this->proyectoParticipanteService->list($id, $userId),
            ],
        ]);
    }

    public function detach(Request $request, int $id): JsonResponse
    {
        return match ($this->proyectoParticipanteService->detach($id, $this->userId($request))['status']) {
            'success' => response()->json(['message' => 'Participacion desvinculada correctamente']),
            'not_found' => response()->json(['message' => 'Participacion no encontrada'], 404),
            'only_participant' => response()->json([
                'message' => 'No puedes desvincularte porque eres el unico participante del proyecto. Puedes eliminar el proyecto.',
            ], 422),
            'sole_owner' => response()->json([
                'message' => 'No puedes desvincularte porque eres el propietario principal del proyecto. Elimina el proyecto o asigna otro propietario antes de salir.',
            ], 422),
            default => response()->json(['message' => 'No tienes permiso para desvincular esta participacion'], 403),
        };
    }

    public function remove(Request $request, int $id, int $participacionId): JsonResponse
    {
        return match ($this->proyectoParticipanteService->remove($id, $participacionId, $this->userId($request))['status']) {
            'success' => response()->json(['message' => 'Participacion sin validacion quitada correctamente']),
            'not_found' => response()->json(['message' => 'Participacion no encontrada'], 404),
            'self' => response()->json(['message' => 'Usa la opcion de desvincular tu propia participacion.'], 422),
            'owner' => response()->json(['message' => 'No puedes quitar al propietario del proyecto desde esta opcion.'], 422),
            'validated' => response()->json(['message' => 'Solo se pueden quitar participantes sin validacion GitHub desde esta opcion.'], 422),
            'only_participant' => response()->json(['message' => 'No puedes quitar al unico participante del proyecto.'], 422),
            default => response()->json(['message' => 'No tienes permiso para quitar participantes sin validacion'], 403),
        };
    }

    private function userId(Request $request): int
    {
        return (int) ($request->user()->id_usuario ?? 0);
    }
}
