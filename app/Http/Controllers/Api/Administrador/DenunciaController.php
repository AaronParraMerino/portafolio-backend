<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Models\Denuncia;
use App\Services\api\Denuncias\AdminDenunciaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DenunciaController extends Controller
{
    public function __construct(private readonly AdminDenunciaService $denunciaService) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->user()?->rol !== 'admin') {
            return response()->json(['message' => 'No tienes permiso para revisar denuncias.'], 403);
        }

        $filters = $request->validate([
            'estado' => ['nullable', 'string', 'in:pendiente,en_revision,resuelta,descartada'],
            'q' => ['nullable', 'string', 'max:180'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $page = $this->denunciaService->listar($filters);

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $page->getCollection()
                    ->map(fn (Denuncia $denuncia) => $this->denunciaService->serializar($denuncia))
                    ->values(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($request->user()?->rol !== 'admin') {
            return response()->json(['message' => 'No tienes permiso para revisar denuncias.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->denunciaService->serializar($this->denunciaService->obtener($id)),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($request->user()?->rol !== 'admin') {
            return response()->json(['message' => 'No tienes permiso para revisar denuncias.'], 403);
        }

        $data = $request->validate([
            'estado' => ['required', 'string', 'in:pendiente,en_revision,resuelta,descartada'],
            'respuesta_admin' => ['nullable', 'string', 'max:4000'],
        ]);

        $denuncia = $this->denunciaService->actualizarEstado(
            $this->denunciaService->obtener($id),
            $data,
            (int) $request->user()->id_usuario
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Denuncia actualizada correctamente.',
            'data' => $this->denunciaService->serializar($denuncia),
        ]);
    }
}
