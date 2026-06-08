<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Services\api\BitacoraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BitacoraController extends Controller
{
    public function __construct(
        private readonly BitacoraService $bitacoraService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'search' => ['nullable', 'string', 'max:200'],
            'accion' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:100'],
            'modulo' => ['nullable', 'string', 'max:100'],
            'module' => ['nullable', 'string', 'max:100'],
            'usuario_id' => ['nullable', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'desde' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->bitacoraService->search($filters));
    }

    private function forbidNonAdmin(Request $request): ?JsonResponse
    {
        if ($request->user()?->rol === 'admin') {
            return null;
        }

        return response()->json([
            'message' => 'No tienes permiso para acceder a la bitacora administrativa.',
        ], 403);
    }
}
