<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\api\SeccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsuarioSesionController extends Controller
{
    public function __construct(private readonly SeccionService $seccionService) {}

    public function index(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        if (! Usuario::query()->whereKey($id)->exists()) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        $sessions = $this->seccionService->listActiveByUser($id)
            ->map(fn (array $session): array => [
                'id_rastreo_interno' => $session['id_rastreo_interno'],
                'ip_address' => $session['ip_address'],
                'pais_codigo' => $session['pais_codigo'],
                'navegador_nombre' => $session['navegador_nombre'],
                'navegador_version' => $session['navegador_version'],
                'sistema_operativo' => $session['sistema_operativo'],
                'es_movil' => $session['es_movil'],
                'ultima_actividad' => $session['ultima_actividad'],
            ]);

        return response()->json(['data' => $sessions]);
    }

    public function destroy(Request $request, int $id, int $sessionId): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $status = $this->seccionService->closeSessionByIdForUser($sessionId, $id);
        if ($status === 'not_found') {
            return response()->json(['message' => 'Sesion no encontrada.'], 404);
        }

        return response()->json(['message' => 'Sesion cerrada correctamente.']);
    }

    public function destroyAll(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        if (! Usuario::query()->whereKey($id)->exists()) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        $closedCount = $this->seccionService->closeOtherSessionsForUser($id);

        return response()->json([
            'message' => 'Sesiones cerradas correctamente.',
            'data' => ['cerradas' => $closedCount],
        ]);
    }

    private function forbidNonAdmin(Request $request): ?JsonResponse
    {
        if ($request->user()?->rol === 'admin') {
            return null;
        }

        return response()->json([
            'message' => 'No tienes permiso para acceder a la administracion de usuarios.',
        ], 403);
    }
}
