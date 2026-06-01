<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\AdminEventoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicanteSolicitudController extends Controller
{
    public function __construct(
        private readonly AdminEventoService $eventoService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'documento' => ['required_without:documentId', 'string', 'min:5', 'max:50'],
            'documentId' => ['required_without:documento', 'string', 'min:5', 'max:50'],
            'telefono_actual' => ['required_without:currentPhone', 'string', 'max:30'],
            'currentPhone' => ['required_without:telefono_actual', 'string', 'max:30'],
            'telefono_referencia' => ['required_without:referencePhone', 'string', 'max:30'],
            'referencePhone' => ['required_without:telefono_referencia', 'string', 'max:30'],
            'correo_respaldo' => ['required_without:backupEmail', 'email', 'max:150'],
            'backupEmail' => ['required_without:correo_respaldo', 'email', 'max:150'],
            'organizacion' => ['required_without:organization', 'string', 'min:3', 'max:150'],
            'organization' => ['required_without:organizacion', 'string', 'min:3', 'max:150'],
            'cargo' => ['required_without:role', 'string', 'min:3', 'max:100'],
            'role' => ['required_without:cargo', 'string', 'min:3', 'max:100'],
            'motivo' => ['required_without:reason', 'string', 'min:30', 'max:1000'],
            'reason' => ['required_without:motivo', 'string', 'min:30', 'max:1000'],
            'experiencia' => ['required_without:experience', 'string', 'min:30', 'max:1000'],
            'experience' => ['required_without:experiencia', 'string', 'min:30', 'max:1000'],
            'enlaces' => ['required_without:links', 'string', 'max:1000'],
            'links' => ['required_without:enlaces', 'string', 'max:1000'],
        ]);

        try {
            $publisherRequest = $this->eventoService->createPublisherRequest($request->user(), $data);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Solicitud enviada correctamente.',
            'data' => $this->eventoService->formatPublisherRequest($publisherRequest),
        ], 201);
    }
}
