<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Services\api\Administrador\AdminUsuarioPanelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsuarioConsultaController extends Controller
{
    public function __construct(private readonly AdminUsuarioPanelService $adminUsuarioPanelService) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->user()?->rol !== 'admin') {
            return response()->json([
                'message' => 'No tienes permiso para acceder a la administracion de usuarios.',
            ], 403);
        }

        return response()->json($this->adminUsuarioPanelService->getPanel());
    }
}
