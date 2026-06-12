<?php

namespace App\Http\Controllers\Api\Proyecto;

use App\Http\Controllers\Controller;
use App\Services\api\Proyecto\ProyectoMediaService;
use App\Services\api\Proyecto\ProyectoPermisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProyectoMediaController extends Controller
{
    public function __construct(
        private readonly ProyectoPermisoService $proyectoPermisoService,
        private readonly ProyectoMediaService $proyectoMediaService,
    ) {}

    public function uploadImages(Request $request, int $id): JsonResponse
    {
        if ($response = $this->authorizeEdition($request, $id)) {
            return $response;
        }

        $request->validate([
            'images.*' => 'sometimes|file|image|max:2048',
            'imagenes.*' => 'sometimes|file|image|max:2048',
        ], [
            'images.*.uploaded' => 'No se puede subir archivos mayores a 2 MB.',
            'imagenes.*.uploaded' => 'No se puede subir archivos mayores a 2 MB.',
            'images.*.max' => 'No se puede subir archivos mayores a 2 MB.',
            'imagenes.*.max' => 'No se puede subir archivos mayores a 2 MB.',
        ]);

        $files = $request->file('images', []);
        if ($files === []) {
            $files = $request->file('imagenes', []);
        }

        $saved = $this->proyectoMediaService->uploadImages($id, $this->userId($request), $files);

        return response()->json(['urls' => $saved, 'imagenes' => $saved]);
    }

    public function deleteImages(Request $request, int $id): JsonResponse
    {
        if ($response = $this->authorizeEdition($request, $id)) {
            return $response;
        }

        $urls = collect($request->input('urls', $request->input('imagenes', [])))
            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
            ->values()
            ->all();

        if ($urls === []) {
            return response()->json(['message' => 'Sin imágenes para eliminar']);
        }

        $deleted = $this->proyectoMediaService->deleteImages($id, $this->userId($request), $urls);

        if ($deleted === 0) {
            return response()->json(['message' => 'No se encontraron imágenes activas para eliminar'], 422);
        }

        return response()->json([
            'message' => 'Imágenes eliminadas correctamente',
            'eliminadas' => $deleted,
        ]);
    }

    public function reorderImages(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'Reordenado no implementado', 'ok' => true]);
    }

    public function repairImageVariants(Request $request, int $id): JsonResponse
    {
        if ($response = $this->authorizeEdition($request, $id)) {
            return $response;
        }

        $data = $request->validate([
            'original_url' => 'required|string|url|max:1000',
        ]);
        $result = $this->proyectoMediaService->repairImageVariants($id, $data['original_url']);

        return match ($result['status']) {
            'repaired' => response()->json([
                'message' => 'Variantes generadas correctamente',
                'data' => $result,
            ]),
            'original_missing' => response()->json([
                'message' => 'La imagen original ya no existe; se retiró la evidencia perdida',
                'data' => $result,
            ]),
            default => response()->json(['message' => 'Imagen no encontrada en el proyecto'], 404),
        };
    }

    public function uploadDocuments(Request $request, int $id): JsonResponse
    {
        if ($response = $this->authorizeEdition($request, $id)) {
            return $response;
        }

        $request->validate([
            'documents.*' => 'sometimes|file|max:2048',
            'documentos.*' => 'sometimes|file|max:2048',
        ], [
            'documents.*.uploaded' => 'No se puede subir archivos mayores a 2 MB.',
            'documentos.*.uploaded' => 'No se puede subir archivos mayores a 2 MB.',
            'documents.*.max' => 'No se puede subir archivos mayores a 2 MB.',
            'documentos.*.max' => 'No se puede subir archivos mayores a 2 MB.',
        ]);

        $files = $request->file('documents', []);
        if ($files === []) {
            $files = $request->file('documentos', []);
        }

        $docs = $this->proyectoMediaService->uploadDocuments($id, $this->userId($request), $files);

        return response()->json([
            'documents' => $docs,
            'documentos' => $docs,
            'urls' => collect($docs)->pluck('url')->values(),
        ]);
    }

    public function deleteDocuments(Request $request, int $id): JsonResponse
    {
        if ($response = $this->authorizeEdition($request, $id)) {
            return $response;
        }

        $urls = collect($request->input('urls', $request->input('documentos', [])))
            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
            ->values()
            ->all();

        if ($urls === []) {
            return response()->json(['message' => 'Sin documentos para eliminar']);
        }

        $deleted = $this->proyectoMediaService->deleteDocuments($id, $this->userId($request), $urls);

        if ($deleted === 0) {
            return response()->json(['message' => 'No se encontraron documentos activos para eliminar'], 422);
        }

        return response()->json([
            'message' => 'Documentos eliminados correctamente',
            'eliminados' => $deleted,
        ]);
    }

    public function reorderDocuments(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'Reordenado no implementado', 'ok' => true]);
    }

    private function authorizeEdition(Request $request, int $id): ?JsonResponse
    {
        $userId = $this->userId($request);

        if (! $this->proyectoPermisoService->userHasAccess($userId, $id)) {
            return response()->json(['message' => 'Proyecto no encontrado'], 404);
        }

        if (! $this->proyectoPermisoService->resolve($userId, $id)['puede_editar']) {
            return response()->json(['message' => 'No tienes permiso para editar este proyecto'], 403);
        }

        return null;
    }

    private function userId(Request $request): int
    {
        return (int) ($request->user()->id_usuario ?? 0);
    }
}
