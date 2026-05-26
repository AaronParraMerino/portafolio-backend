<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Services\api\NotificacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificacionController extends Controller
{
    public function __construct(
        private readonly NotificacionService $notificacionService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->user()?->rol !== 'admin') {
            return response()->json([
                'message' => 'No tienes permiso para enviar avisos a usuarios.',
            ], 403);
        }

        $data = $request->validate([
            'destinatarios' => ['required', 'array', 'min:1', 'max:500'],
            'destinatarios.*' => ['required', 'integer', 'distinct', 'exists:usuarios,id_usuario'],
            'titulo' => ['required', 'string', 'max:50'],
            'contenido' => ['required', 'string', 'max:2000'],
            'tipo' => ['required', Rule::in([
                'bienvenida',
                'cuenta',
                'seguridad',
                'actividad',
                'sistema',
                'capacitacion',
            ])],
            'urgencia' => ['required', Rule::in(['baja', 'media', 'alta'])],
            'canales' => ['sometimes', 'array'],
            'canales.*' => [Rule::in(['inapp'])],
            'segmentos' => ['sometimes', 'array'],
            'segmentos.*' => [Rule::in([
                'todos',
                'activos',
                'pausados',
                'bloqueados',
                'inactivos',
                'seleccionados',
            ])],
        ]);

        return response()->json(
            $this->notificacionService->createAdminNotice(
                (int) $request->user()->id_usuario,
                $data
            ),
            201
        );
    }
}
