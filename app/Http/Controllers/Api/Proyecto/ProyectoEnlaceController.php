<?php

namespace App\Http\Controllers\Api\Proyecto;

use App\Http\Controllers\Controller;
use App\Services\api\Proyecto\ProyectoConsultaService;
use App\Services\api\Proyecto\ProyectoEnlaceService;
use App\Services\api\Proyecto\ProyectoPermisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProyectoEnlaceController extends Controller
{
    public function __construct(
        private readonly ProyectoEnlaceService $proyectoEnlaceService,
        private readonly ProyectoConsultaService $proyectoConsultaService,
        private readonly ProyectoPermisoService $proyectoPermisoService,
    ) {}

    public function update(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()->id_usuario ?? 0);
        if (! $this->proyectoConsultaService->findForUser($userId, $id)) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }
        if (! $this->proyectoPermisoService->resolve($userId, $id)['puede_editar']) {
            return response()->json(['message' => 'No tienes permiso para editar este proyecto'], 403);
        }

        $data = $this->proyectoEnlaceService->update(
            $userId,
            $id,
            $request->validate(ProyectoEnlaceService::validationRules())
        );

        return response()->json(['data' => $data]);
    }
}
