<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\Denuncias\DenunciaService;
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
            'detalle' => ['required', 'string', 'max:4000'],
            'evidencia' => ['sometimes', 'array'],
            'evidencia_imagen' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $data['metadata'] = array_merge($data['metadata'] ?? [], [
            'url' => $request->input('metadata.url'),
            'modulo' => $request->input('metadata.modulo'),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        $response = $this->service->crearDenuncia(
            (int) $request->user()->id_usuario,
            $data,
            $request->file('evidencia_imagen')
        );

        return response()->json(
            $response,
            ($response['status'] ?? null) === 'success' ? 201 : 422
        );
    }
}
