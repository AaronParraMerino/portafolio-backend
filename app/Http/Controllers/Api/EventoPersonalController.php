<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\EventoPersonalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\api\EventosNotificacionGuardadoService;

class EventoPersonalController extends Controller
{
    public function __construct(
        private readonly EventoPersonalService $service,
        private readonly EventosNotificacionGuardadoService $eventosNotificacionGuardadoService

        )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'mes' => ['nullable', 'date_format:Y-m'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = (int) $request->user()->id_usuario;

        return response()->json([
            'data' => $this->service->getByUser($userId, $validator->validated()['mes'] ?? null),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->storeRules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $evento = $this->service->create((int) $request->user()->id_usuario, $validator->validated());

            // Seccion para guardar notificacion
            $this->eventosNotificacionGuardadoService->notificarEventoCreadoPersonal($evento);
            // Fin seccion para guardar notificacion

            return response()->json([
                'message' => 'Evento creado correctamente',
                'data' => $evento,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->updateRules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $evento = $this->service->update((int) $request->user()->id_usuario, $id, $validator->validated());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        if (! $evento) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        // Sección para guardar notificación
        $this->eventosNotificacionGuardadoService->notificarEventoActualizadoPersonal($evento);
        // Fin sección para guardar notificación

        return response()->json([
            'message' => 'Evento actualizado correctamente',
            'data' => $evento,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = $this->service->delete((int) $request->user()->id_usuario, $id);

        if (! $deleted) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        return response()->json([
            'message' => 'Evento eliminado correctamente',
        ]);
    }

    public function destroyByDate(Request $request, string $fecha): JsonResponse
    {
        $validator = Validator::make(['fecha' => $fecha], [
            'fecha' => ['required', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $eliminados = $this->service->deleteByDate(
            (int) $request->user()->id_usuario,
            $validator->validated()['fecha']
        );

        return response()->json([
            'message' => 'Eventos del día eliminados correctamente',
            'eliminados' => $eliminados,
        ]);
    }

    private function storeRules(): array
    {
        return [
            'titulo' => [
                'required',
                'string',
                'max:50',
                'regex:/^[\pL\s]+$/u',
                'not_regex:/https?:\/\/|www\./i',
            ],
            'descripcion' => [
                'nullable',
                'string',
                'max:160',
                'not_regex:/https?:\/\/|www\./i',
                'not_regex:/[#\$%&\/\*°]/u',
            ],
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'hora' => ['required', 'date_format:H:i'],
            'tipo' => ['required', 'string', 'max:30'],
        ];
    }

    private function updateRules(): array
    {
        return [
            'titulo' => [
                'sometimes',
                'string',
                'max:50',
                'regex:/^[\pL\s]+$/u',
                'not_regex:/https?:\/\/|www\./i',
            ],
            'descripcion' => [
                'nullable',
                'string',
                'max:160',
                'not_regex:/https?:\/\/|www\./i',
                'not_regex:/[#\$%&\/\*°]/u',
            ],
            'fecha' => ['sometimes', 'date_format:Y-m-d'],
            'hora' => ['sometimes', 'date_format:H:i'],
            'tipo' => ['sometimes', 'string', 'max:30'],
        ];
    }
}
