<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminEvento;
use App\Services\api\AdminEventoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicanteEventoController extends Controller
{
    public function __construct(
        private readonly AdminEventoService $eventoService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonPublisher($request)) {
            return $forbidden;
        }

        return response()->json([
            'data' => $this->eventoService->publisherEvents($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonPublisher($request)) {
            return $forbidden;
        }

        $data = $this->validateEvent($request);

        try {
            $event = $this->eventoService->createPublisherEvent(
                $request->user(),
                $data,
                $request->file('imagen_portada') ?? $request->file('imageFile')
            );
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Evento creado correctamente.',
            'data' => $this->eventoService->formatEvent($event),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonPublisher($request)) {
            return $forbidden;
        }

        $event = AdminEvento::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Evento no encontrado.'], 404);
        }

        $data = $this->validateEvent($request, true);

        try {
            $event = $this->eventoService->updatePublisherEvent(
                $request->user(),
                $event,
                $data,
                $request->file('imagen_portada') ?? $request->file('imageFile')
            );
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Evento actualizado correctamente.',
            'data' => $this->eventoService->formatEvent($event),
        ]);
    }

    private function validateEvent(Request $request, bool $updating = false): array
    {
        $this->decodeJsonFields($request, [
            'channels',
            'canales',
            'segments',
            'segmentos',
            'targetSelections',
            'target_selections',
        ]);

        $titleRule = $updating ? 'sometimes' : 'required_without:title';
        $titleAliasRule = $updating ? 'sometimes' : 'required_without:titulo';
        $locationRule = $updating ? 'sometimes' : 'required_without:location';
        $locationAliasRule = $updating ? 'sometimes' : 'required_without:ubicacion';

        return $request->validate([
            'titulo' => [$titleRule, 'string', 'max:150'],
            'title' => [$titleAliasRule, 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:3000'],
            'description' => ['nullable', 'string', 'max:3000'],
            'tipo' => ['sometimes', Rule::in($this->eventTypes())],
            'type' => ['sometimes', Rule::in($this->eventTypes())],
            'estado' => ['sometimes', Rule::in(['activo', 'programado', 'borrador'])],
            'status' => ['sometimes', Rule::in(['activo', 'programado', 'borrador'])],
            'fecha_inicio' => ['nullable', 'date'],
            'startsAt' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date'],
            'ubicacion' => [$locationRule, 'string', 'max:255'],
            'location' => [$locationAliasRule, 'string', 'max:255'],
            'cupo' => ['nullable', 'integer', 'min:0'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'programado_para' => ['nullable', 'date'],
            'sendAt' => ['nullable', 'date'],
            'target_mode' => ['sometimes', Rule::in(['all_users', 'segmented'])],
            'targetMode' => ['sometimes', Rule::in(['all_users', 'segmented'])],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', 'max:40'],
            'canales' => ['nullable', 'array'],
            'canales.*' => ['string', 'max:40'],
            'segments' => ['nullable', 'array'],
            'segmentos' => ['nullable', 'array'],
            'targetSelections' => ['nullable', 'array'],
            'target_selections' => ['nullable', 'array'],
            'imagen_portada' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'imageFile' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'imageUrl' => ['nullable', 'string', 'max:500'],
            'imagen_url' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function decodeJsonFields(Request $request, array $fields): void
    {
        $decoded = [];

        foreach ($fields as $field) {
            $value = $request->input($field);

            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '' || ! in_array($trimmed[0], ['[', '{'], true)) {
                continue;
            }

            $json = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded[$field] = $json;
            }
        }

        if (! empty($decoded)) {
            $request->merge($decoded);
        }
    }

    private function forbidNonPublisher(Request $request): ?JsonResponse
    {
        if ($request->user()?->rol === 'publicante') {
            return null;
        }

        return response()->json([
            'message' => 'Necesitas rol publicante para gestionar eventos.',
        ], 403);
    }

    private function eventTypes(): array
    {
        return [
            'taller',
            'charla',
            'webinar',
            'feria',
            'capacitacion',
            'networking',
            'curso',
            'trabajo',
            'convocatoria',
        ];
    }
}
