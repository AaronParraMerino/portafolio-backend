<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\PersonalizacionPortafolioService;
use App\Services\api\PortafolioPublicoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PersonalizacionPortafolioController extends Controller
{
    public function __construct(
        private readonly PersonalizacionPortafolioService $service,
        private readonly PortafolioPublicoService $publicService
    ) {
    }

    public function publicView(int $userId): JsonResponse
    {
        $portafolio = $this->publicService->getByUser($userId);

        if (! $portafolio) {
            return response()->json(['message' => 'Portafolio no disponible'], 404);
        }

        return response()->json(['data' => $portafolio]);
    }

    public function show(int $userId): JsonResponse
    {
        $configuracion = $this->service->getByUser($userId);

        if (! $configuracion) {
            return response()->json((object) []);
        }

        return response()->json($configuracion);
    }

    public function update(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();

        if (! $user || (int) $user->id_usuario !== $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate($this->rules());

        return response()->json(
            $this->service->saveForUser($userId, $data)
        );
    }

    private function rules(): array
    {
        $hexColor = ['sometimes', 'string', 'max:20', 'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/'];

        return [
            'hero_color' => $hexColor,
            'accent_color' => $hexColor,
            'card_bg' => $hexColor,
            'text_color' => $hexColor,
            'avatar_color' => $hexColor,
            'hero_bg_source' => ['sometimes', Rule::in(['foto', 'custom'])],
            'avatar_bg_source' => ['sometimes', Rule::in(['foto', 'custom'])],
            'hero_pattern' => ['sometimes', Rule::in(['dots', 'grid', 'hex', 'none'])],
            'font_id' => ['sometimes', Rule::in(['inter', 'mono', 'georgia', 'system'])],
            'frame_id' => ['sometimes', Rule::in(['thick', 'mac', 'linux', 'windows', 'none'])],
            'text_color_auto' => ['sometimes', 'boolean'],
            'disponible' => ['sometimes', 'boolean'],
            'visibilidad' => ['sometimes', 'array'],
            'visibilidad.perfil' => ['sometimes', 'array'],
            'visibilidad.perfil.*' => ['boolean'],
            'visibilidad.stats' => ['sometimes', 'array'],
            'visibilidad.stats.*' => ['boolean'],
            'visibilidad.habilidades' => ['sometimes', 'array'],
            'visibilidad.habilidades.*' => ['boolean'],
            'visibilidad.experiencias' => ['sometimes', 'array'],
            'visibilidad.experiencias.*' => ['boolean'],
            'visibilidad.proyectos' => ['sometimes', 'array'],
            'visibilidad.proyectos.*' => ['boolean'],
            'visibilidad.proyecto_detalles' => ['sometimes', 'array'],
            'visibilidad.proyecto_detalles.*' => ['boolean'],
        ];
    }
}
