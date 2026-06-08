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

        $this->proyectoMediaService->deleteImages($id, $this->userId($request), $urls);

        return response()->json(['message' => 'Imágenes eliminadas correctamente']);
    }

    public function reorderImages(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'Reordenado no implementado', 'ok' => true]);
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

        $this->proyectoMediaService->deleteDocuments($id, $this->userId($request), $urls);

        return response()->json(['message' => 'Documentos eliminados correctamente']);
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
