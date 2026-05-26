<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Models\SesionBase;
use App\Models\Usuario;
use App\Services\api\SeccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsuarioController extends Controller
{
    public function __construct(private readonly SeccionService $seccionService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $usuarios = Usuario::query()
            ->select([
                'id_usuario',
                'nombre',
                'apellido',
                'correo',
                'rol',
                'estado',
                'created_at',
            ])
            ->orderBy('id_usuario')
            ->get();

        $sessionCounts = SesionBase::query()
            ->whereNotNull('personal_access_token_id')
            ->whereIn('usuario_id', $usuarios->pluck('id_usuario'))
            ->selectRaw('usuario_id, COUNT(*) as total')
            ->groupBy('usuario_id')
            ->pluck('total', 'usuario_id');

        $items = $usuarios->map(function (Usuario $usuario) use ($sessionCounts): array {
            return [
                'id' => $usuario->id_usuario,
                'nombre' => trim($usuario->nombre.' '.$usuario->apellido),
                'email' => $usuario->correo,
                'rol' => $usuario->rol,
                'estado' => $usuario->estado,
                'fechaRegistro' => $usuario->created_at?->format('d/m/Y'),
                'sesionesActivas' => (int) ($sessionCounts[$usuario->id_usuario] ?? 0),
            ];
        })->values();

        return response()->json([
            'items' => $items,
            'metrics' => [
                'total' => $usuarios->count(),
                'activo' => $usuarios->where('estado', 'activo')->count(),
                'pausado' => $usuarios->where('estado', 'pausado')->count(),
                'bloqueado' => $usuarios->where('estado', 'bloqueado')->count(),
                'inactivo' => $usuarios->where('estado', 'inactivo')->count(),
            ],
            'sourceReady' => true,
            'supportsMutations' => false,
            'supportsSessions' => false,
        ]);
    }

    public function sessions(Request $request, int $id): JsonResponse
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

    public function closeSession(Request $request, int $id, int $sessionId): JsonResponse
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

    public function closeAllSessions(Request $request, int $id): JsonResponse
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
