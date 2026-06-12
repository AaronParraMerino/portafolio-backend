<?php

namespace App\Http\Controllers\Api\Proyecto;

use App\Http\Controllers\Controller;
use App\Services\api\Proyecto\ProyectoRestauracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProyectoRestauracionController extends Controller
{
    public function __construct(
        private readonly ProyectoRestauracionService $proyectoRestauracionService,
    ) {}

    public function restore(Request $request, int $id): JsonResponse
    {
        $result = $this->proyectoRestauracionService->restore($this->userId($request), $id);

        return match ($result['status'] ?? 'error') {
            'success' => response()->json([
                'message' => 'Proyecto restablecido correctamente',
                'data' => $result,
            ]),
            'not_found' => response()->json(['message' => 'Proyecto eliminado no encontrado'], 404),
            default => response()->json(['message' => 'No tienes permiso para restablecer este proyecto'], 403),
        };
    }

    public function requestRestore(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['message' => 'nullable|string|max:600']);
        $result = $this->proyectoRestauracionService->requestRestore(
            $this->userId($request),
            $id,
            $data['message'] ?? null
        );

        return match ($result['status'] ?? 'error') {
            'success' => response()->json(['message' => 'Solicitud enviada a los propietarios', 'data' => $result], 201),
            'not_found' => response()->json(['message' => 'Proyecto eliminado no encontrado'], 404),
            'owner_can_restore' => response()->json(['message' => 'Puedes restablecer directamente este proyecto'], 422),
            'pending' => response()->json(['message' => 'Ya existe una solicitud pendiente', 'data' => $result], 409),
            'cooldown' => response()->json(['message' => 'Debes esperar antes de volver a solicitar', 'data' => $result], 429),
            'no_owners' => response()->json(['message' => 'No existen propietarios validados para recibir la solicitud'], 422),
            default => response()->json(['message' => 'No tienes una colaboracion validada para solicitar el restablecimiento'], 403),
        };
    }

    public function respond(Request $request, int $id, int $notificationId): JsonResponse
    {
        $data = $request->validate([
            'decision' => 'required|string|in:aprobar,rechazar',
            'response' => 'nullable|string|max:600',
        ]);
        $result = $this->proyectoRestauracionService->respond(
            $this->userId($request),
            $id,
            $notificationId,
            $data['decision'],
            $data['response'] ?? null
        );

        return match ($result['status'] ?? 'error') {
            'approved' => response()->json(['message' => 'Solicitud aprobada y proyecto restablecido', 'data' => $result]),
            'rejected' => response()->json(['message' => 'Solicitud rechazada', 'data' => $result]),
            'not_found' => response()->json(['message' => 'Solicitud o proyecto no encontrado'], 404),
            'already_resolved' => response()->json(['message' => 'La solicitud ya fue respondida'], 409),
            'invalid_decision' => response()->json(['message' => 'Decision invalida'], 422),
            default => response()->json(['message' => 'No tienes permiso para responder esta solicitud'], 403),
        };
    }

    public function releaseRepository(Request $request, int $id, int $repositoryId): JsonResponse
    {
        $result = $this->proyectoRestauracionService->releaseRepository(
            $this->userId($request),
            $id,
            $repositoryId,
            $request->input('confirmation_title')
        );

        return match ($result['status'] ?? 'error') {
            'released' => response()->json(['message' => 'Repositorio liberado correctamente', 'data' => $result]),
            'released_and_project_deleted' => response()->json([
                'message' => 'Ultimo repositorio liberado y proyecto eliminado permanentemente',
                'data' => $result,
            ]),
            'confirmation_required' => response()->json([
                'message' => 'Este es el ultimo repositorio. Confirma la eliminacion permanente escribiendo el titulo.',
                'data' => $result,
            ], 409),
            'not_found', 'repository_not_found' => response()->json(['message' => 'Proyecto o repositorio no encontrado'], 404),
            default => response()->json(['message' => 'No tienes permiso para liberar este repositorio'], 403),
        };
    }

    private function userId(Request $request): int
    {
        return (int) ($request->user()->id_usuario ?? 0);
    }
}
