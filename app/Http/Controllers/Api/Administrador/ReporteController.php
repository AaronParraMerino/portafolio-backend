<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Services\api\Administrador\AdminReporteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReporteController extends Controller
{
    public function __construct(private readonly AdminReporteService $adminReporteService) {}

    public function summary(Request $request): JsonResponse
    {
        if ($request->user()?->rol !== 'admin') {
            return response()->json([
                'message' => 'No tienes permiso para acceder a los reportes administrativos.',
            ], 403);
        }

        $filters = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        return response()->json($this->adminReporteService->summary($filters));
    }
}
