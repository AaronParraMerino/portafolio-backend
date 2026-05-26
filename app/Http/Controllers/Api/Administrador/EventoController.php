<?php

namespace App\Http\Controllers\Api\Administrador;

use App\Http\Controllers\Controller;
use App\Models\AdminEvento;
use App\Models\AdminEventoComunicacion;
use App\Models\AdminEventoPlantilla;
use App\Services\api\AdminEventoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventoController extends Controller
{
    public function __construct(
        private readonly AdminEventoService $eventoService
    ) {
    }

    public function workspace(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        return response()->json($this->eventoService->workspace());
    }

    public function store(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $data = $this->validateEvent($request);
        $event = $this->eventoService->createEvent($data, (int) $request->user()->id_usuario);

        return response()->json([
            'message' => 'Evento creado correctamente.',
            'data' => $this->eventoService->formatEvent($event),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $event = AdminEvento::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Evento no encontrado.'], 404);
        }

        $data = $this->validateEvent($request, true);
        $event = $this->eventoService->updateEvent($event, $data, (int) $request->user()->id_usuario);

        return response()->json([
            'message' => 'Evento actualizado correctamente.',
            'data' => $this->eventoService->formatEvent($event),
        ]);
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $event = AdminEvento::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Evento no encontrado.'], 404);
        }

        $copy = $this->eventoService->duplicateEvent($event, (int) $request->user()->id_usuario);

        return response()->json([
            'message' => 'Evento duplicado correctamente.',
            'data' => $this->eventoService->formatEvent($copy),
        ], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $event = AdminEvento::query()->find($id);

        if (! $event) {
            return response()->json(['message' => 'Evento no encontrado.'], 404);
        }

        $event = $this->eventoService->cancelEvent($event, (int) $request->user()->id_usuario);

        return response()->json([
            'message' => 'Evento cancelado correctamente.',
            'data' => $this->eventoService->formatEvent($event),
        ]);
    }

    public function storeCommunication(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $data = $this->validateCommunication($request);
        $communication = $this->eventoService->createCommunication($data, (int) $request->user()->id_usuario);

        return response()->json([
            'message' => 'Comunicacion creada correctamente.',
            'data' => $this->eventoService->formatCommunication($communication),
        ], 201);
    }

    public function updateCommunication(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $communication = AdminEventoComunicacion::query()->find($id);

        if (! $communication) {
            return response()->json(['message' => 'Comunicacion no encontrada.'], 404);
        }

        $data = $this->validateCommunication($request, true);
        $communication = $this->eventoService->updateCommunication($communication, $data, (int) $request->user()->id_usuario);

        return response()->json([
            'message' => 'Comunicacion actualizada correctamente.',
            'data' => $this->eventoService->formatCommunication($communication),
        ]);
    }

    public function archiveCommunication(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $communication = AdminEventoComunicacion::query()->find($id);

        if (! $communication) {
            return response()->json(['message' => 'Comunicacion no encontrada.'], 404);
        }

        $communication = $this->eventoService->archiveCommunication($communication, (int) $request->user()->id_usuario);

        return response()->json([
            'message' => 'Comunicacion archivada correctamente.',
            'data' => $this->eventoService->formatCommunication($communication),
        ]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $data = $this->validateTemplate($request);
        $template = $this->eventoService->createTemplate($data, (int) $request->user()->id_usuario);

        return response()->json([
            'message' => 'Plantilla creada correctamente.',
            'data' => $this->eventoService->formatTemplate($template),
        ], 201);
    }

    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->forbidNonAdmin($request)) {
            return $forbidden;
        }

        $template = AdminEventoPlantilla::query()->find($id);

        if (! $template) {
            return response()->json(['message' => 'Plantilla no encontrada.'], 404);
        }

        $data = $this->validateTemplate($request, true);
        $template = $this->eventoService->updateTemplate($template, $data, (int) $request->user()->id_usuario);

        return response()->json([
            'message' => 'Plantilla actualizada correctamente.',
            'data' => $this->eventoService->formatTemplate($template),
        ]);
    }

    private function validateEvent(Request $request, bool $updating = false): array
    {
        $titleRule = $updating ? 'sometimes' : 'required_without:title';
        $titleAliasRule = $updating ? 'sometimes' : 'required_without:titulo';

        return $request->validate([
            'titulo' => [$titleRule, 'string', 'max:150'],
            'title' => [$titleAliasRule, 'string', 'max:150'],
            'descripcion' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'tipo' => ['sometimes', Rule::in($this->eventTypes())],
            'type' => ['sometimes', Rule::in($this->eventTypes())],
            'estado' => ['sometimes', Rule::in(['activo', 'programado', 'borrador', 'cancelado'])],
            'status' => ['sometimes', Rule::in(['activo', 'programado', 'borrador', 'cancelado'])],
            'fecha_inicio' => ['nullable', 'date'],
            'startsAt' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date'],
            'programado_para' => ['nullable', 'date'],
            'sendAt' => ['nullable', 'date'],
            'ubicacion' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'cupo' => ['nullable', 'integer', 'min:0'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'inscritos' => ['nullable', 'integer', 'min:0'],
            'registered' => ['nullable', 'integer', 'min:0'],
            'interesados' => ['nullable', 'integer', 'min:0'],
            'interested' => ['nullable', 'integer', 'min:0'],
            'espera' => ['nullable', 'integer', 'min:0'],
            'waitlist' => ['nullable', 'integer', 'min:0'],
            'asistieron' => ['nullable', 'integer', 'min:0'],
            'attended' => ['nullable', 'integer', 'min:0'],
            'no_asistieron' => ['nullable', 'integer', 'min:0'],
            'missed' => ['nullable', 'integer', 'min:0'],
            'target_mode' => ['sometimes', Rule::in(['all_users', 'segmented'])],
            'targetMode' => ['sometimes', Rule::in(['all_users', 'segmented'])],
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['required', 'distinct', Rule::in(['push', 'email', 'inapp'])],
            'canales' => ['sometimes', 'array'],
            'canales.*' => ['required', 'distinct', Rule::in(['push', 'email', 'inapp'])],
            'segments' => ['sometimes', 'array'],
            'segmentos' => ['sometimes', 'array'],
            'target_selections' => ['sometimes', 'array'],
            'targetSelections' => ['sometimes', 'array'],
            'target_selections.technicalSkills' => ['sometimes', 'array'],
            'target_selections.softSkills' => ['sometimes', 'array'],
            'target_selections.academicExperience' => ['sometimes', 'array'],
            'target_selections.workExperience' => ['sometimes', 'array'],
            'targetSelections.technicalSkills' => ['sometimes', 'array'],
            'targetSelections.softSkills' => ['sometimes', 'array'],
            'targetSelections.academicExperience' => ['sometimes', 'array'],
            'targetSelections.workExperience' => ['sometimes', 'array'],
        ]);
    }

    private function validateCommunication(Request $request, bool $updating = false): array
    {
        $titleRule = $updating ? 'sometimes' : 'required_without:title';
        $titleAliasRule = $updating ? 'sometimes' : 'required_without:titulo';

        return $request->validate([
            'evento_id' => ['nullable', 'integer', 'exists:admin_eventos,id_evento'],
            'eventId' => ['nullable', 'integer', 'exists:admin_eventos,id_evento'],
            'titulo' => [$titleRule, 'string', 'max:150'],
            'title' => [$titleAliasRule, 'string', 'max:150'],
            'cuerpo' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'tipo' => ['sometimes', Rule::in($this->communicationTypes())],
            'type' => ['sometimes', Rule::in($this->communicationTypes())],
            'estado' => ['sometimes', Rule::in(['borrador', 'programado', 'enviado', 'archivado'])],
            'status' => ['sometimes', Rule::in(['borrador', 'programado', 'enviado', 'archivado'])],
            'urgencia' => ['sometimes', Rule::in(['baja', 'media', 'alta'])],
            'urgency' => ['sometimes', Rule::in(['baja', 'media', 'alta'])],
            'destinatarios' => ['nullable', 'integer', 'min:0'],
            'audience' => ['nullable', 'integer', 'min:0'],
            'programado_para' => ['nullable', 'date'],
            'scheduledAt' => ['nullable', 'date'],
            'date' => ['nullable', 'date'],
            'enviado_en' => ['nullable', 'date'],
            'audiences' => ['sometimes', 'array'],
            'audiences.*' => ['required', 'distinct', Rule::in(['all_users', 'portfolio_users', 'new_users', 'admins'])],
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['required', 'distinct', Rule::in(['push', 'email', 'inapp'])],
            'canales' => ['sometimes', 'array'],
            'canales.*' => ['required', 'distinct', Rule::in(['push', 'email', 'inapp'])],
            'segments' => ['sometimes', 'array'],
            'segmentos' => ['sometimes', 'array'],
            'pinned' => ['sometimes', 'boolean'],
        ]);
    }

    private function validateTemplate(Request $request, bool $updating = false): array
    {
        $titleRule = $updating ? 'sometimes' : 'required_without_all:title,name';
        $titleAliasRule = $updating ? 'sometimes' : 'required_without_all:titulo,name';
        $nameRule = $updating ? 'sometimes' : 'required_without_all:titulo,title';

        return $request->validate([
            'titulo' => [$titleRule, 'string', 'max:150'],
            'title' => [$titleAliasRule, 'string', 'max:150'],
            'name' => [$nameRule, 'string', 'max:150'],
            'cuerpo' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'descripcion' => ['nullable', 'string'],
            'tipo' => ['sometimes', Rule::in($this->communicationTypes())],
            'type' => ['sometimes', Rule::in($this->communicationTypes())],
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['required', 'distinct', Rule::in(['push', 'email', 'inapp'])],
            'canales' => ['sometimes', 'array'],
            'canales.*' => ['required', 'distinct', Rule::in(['push', 'email', 'inapp'])],
            'payload' => ['sometimes', 'array'],
            'audiences' => ['sometimes', 'array'],
            'segments' => ['sometimes', 'array'],
            'segmentos' => ['sometimes', 'array'],
        ]);
    }

    private function forbidNonAdmin(Request $request): ?JsonResponse
    {
        if ($request->user()?->rol === 'admin') {
            return null;
        }

        return response()->json([
            'message' => 'No tienes permiso para acceder a la administracion de eventos.',
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

    private function communicationTypes(): array
    {
        return [
            'plataforma',
            'oportunidad',
            'seguridad',
            'mantenimiento',
            'comunidad',
            'urgente',
        ];
    }
}
