<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Models\AdminUsuarioPlantilla;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UsuarioPlantillaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        return response()->json([
            'data' => AdminUsuarioPlantilla::query()
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (AdminUsuarioPlantilla $template): array => $this->formatTemplate($template))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $template = AdminUsuarioPlantilla::create([
            ...$this->validatedPayload($request),
            'usuario_creador_id' => (int) $request->user()->id_usuario,
            'usuario_actualizador_id' => (int) $request->user()->id_usuario,
        ]);

        return response()->json([
            'message' => 'Plantilla creada correctamente.',
            'data' => $this->formatTemplate($template),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $template = AdminUsuarioPlantilla::query()->find($id);

        if (! $template) {
            return response()->json(['message' => 'Plantilla no encontrada.'], 404);
        }

        $template->update([
            ...$this->validatedPayload($request),
            'usuario_actualizador_id' => (int) $request->user()->id_usuario,
        ]);

        return response()->json([
            'message' => 'Plantilla actualizada correctamente.',
            'data' => $this->formatTemplate($template->fresh()),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $template = AdminUsuarioPlantilla::query()->find($id);

        if (! $template) {
            return response()->json(['message' => 'Plantilla no encontrada.'], 404);
        }

        $template->delete();

        return response()->json(['message' => 'Plantilla eliminada correctamente.']);
    }

    public function useTemplate(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $template = AdminUsuarioPlantilla::query()->find($id);

        if (! $template) {
            return response()->json(['message' => 'Plantilla no encontrada.'], 404);
        }

        $template->increment('usadas');
        $template->update([
            'usuario_actualizador_id' => (int) $request->user()->id_usuario,
        ]);

        return response()->json([
            'message' => 'Plantilla preparada para crear un aviso.',
            'data' => $this->formatTemplate($template->fresh()),
        ]);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'titulo' => ['required', 'string', 'max:100'],
            'cuerpo' => ['required', 'string', 'max:2000'],
            'tipo' => ['required', Rule::in([
                'bienvenida',
                'cuenta',
                'seguridad',
                'actividad',
                'sistema',
                'capacitacion',
            ])],
            'urgencia' => ['required', Rule::in(['baja', 'media', 'alta'])],
            'canales' => ['required', 'array', 'min:1'],
            'canales.*' => ['required', 'distinct', Rule::in(['inapp'])],
        ]);
    }

    private function formatTemplate(AdminUsuarioPlantilla $template): array
    {
        return [
            'id' => $template->id_plantilla,
            'titulo' => $template->titulo,
            'cuerpo' => $template->cuerpo,
            'tipo' => $template->tipo,
            'urgencia' => $template->urgencia,
            'canales' => $template->canales ?? [],
            'usadas' => (int) $template->usadas,
            'actualizado' => $template->updated_at?->format('d/m/Y H:i'),
        ];
    }

    private function forbidNonAdmin(Request $request): ?JsonResponse
    {
        if ($request->user()?->rol === 'admin') {
            return null;
        }

        return response()->json([
            'message' => 'No tienes permiso para administrar plantillas de avisos.',
        ], 403);
    }
}
