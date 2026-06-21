<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\Mensajeria\DenunciaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DenunciaController extends Controller
{
    public function __construct(
        private readonly DenunciaService $service
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asunto' => ['required', 'string', 'max:180'],
            'motivo' => ['required', 'string', 'max:120'],
            'detalle' => ['nullable', 'string', 'max:4000'],
            'evidencia' => ['sometimes', 'array'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $response = $this->service->crearDenuncia(
            (int) $request->user()->id_usuario,
            $data
        );

        return response()->json(
            $response,
            ($response['status'] ?? null) === 'success' ? 201 : 422
        );
    }
}
