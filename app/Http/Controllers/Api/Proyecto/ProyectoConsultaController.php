<?php

namespace App\Http\Controllers\Api\Proyecto;

use App\Http\Controllers\Controller;
use App\Services\api\Proyecto\ProyectoConsultaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProyectoConsultaController extends Controller
{
    public function __construct(private readonly ProyectoConsultaService $proyectoConsultaService) {}

    public function indexByUsuario(Request $request, int $userId): JsonResponse
    {
        $authUserId = (int) ($request->user()->id_usuario ?? 0);
        if ($authUserId <= 0 || $authUserId !== $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $lang = $request->query('lang', 'es');

        return response()->json([
            'data' => $this->proyectoConsultaService->listByUser($userId, $lang)
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $lang = $request->query('lang', 'es');

        $project = $this->proyectoConsultaService->findSerializedForUser(
            (int) ($request->user()->id_usuario ?? 0),
            $id,
            $lang
        );

        if (! $project) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        return response()->json(['data' => $project]);
    }
}
