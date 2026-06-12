<?php

namespace App\Http\Controllers\Api\Proyecto;

use App\Http\Controllers\Controller;
use App\Services\api\Proyecto\ProyectoConsultaService;
use App\Services\api\Proyecto\ProyectoCicloVidaService;
use App\Services\api\Proyecto\ProyectoCrudService;
use App\Services\api\Proyecto\ProyectoPermisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProyectoCrudController extends Controller
{
    public function __construct(
        private readonly ProyectoCrudService $proyectoCrudService,
        private readonly ProyectoConsultaService $proyectoConsultaService,
        private readonly ProyectoPermisoService $proyectoPermisoService,
        private readonly ProyectoCicloVidaService $proyectoCicloVidaService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId <= 0) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $result = $this->proyectoCrudService->create(
            $userId,
            $request->validate(ProyectoCrudService::validationRules())
        );

        return response()->json($result['body'], $result['status']);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $userId = $this->userId($request);
        $project = $this->proyectoConsultaService->findForUser($userId, $id);
        if (! $project) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }
        if (! $this->proyectoPermisoService->resolve($userId, $id)['puede_editar']) {
            return response()->json(['message' => 'No tienes permiso para editar este proyecto'], 403);
        }

        $data = $this->proyectoCrudService->update(
            $userId,
            $id,
            $project,
            $request->validate(ProyectoCrudService::validationRules(true))
        );

        return response()->json(['data' => $data]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $userId = $this->userId($request);
        if (! $this->proyectoConsultaService->findForUser($userId, $id)) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }
        if (! $this->proyectoPermisoService->resolve($userId, $id)['puede_eliminar']) {
            return response()->json(['message' => 'No tienes permiso para eliminar este proyecto'], 403);
        }

        $result = $this->proyectoCicloVidaService->delete(
            $userId,
            $id,
            $request->input('confirmation_title')
        );

        if (($result['status'] ?? null) === 'confirmation_required') {
            return response()->json($result, 409);
        }
        if (($result['status'] ?? null) === 'not_found') {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        return response()->json([
            'message' => $result['tipo_eliminacion'] === 'permanente'
                ? 'Proyecto eliminado permanentemente'
                : 'Proyecto eliminado; puede recuperarse desde sus repositorios vinculados',
            'data' => $result,
        ]);
    }

    public function deletionPreview(Request $request, int $id): JsonResponse
    {
        $userId = $this->userId($request);
        if (! $this->proyectoConsultaService->findForUser($userId, $id)) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }
        if (! $this->proyectoPermisoService->resolve($userId, $id)['puede_eliminar']) {
            return response()->json(['message' => 'No tienes permiso para eliminar este proyecto'], 403);
        }

        return response()->json(['data' => $this->proyectoCicloVidaService->deletionPreview($id)]);
    }

    private function userId(Request $request): int
    {
        return (int) ($request->user()->id_usuario ?? 0);
    }
}
