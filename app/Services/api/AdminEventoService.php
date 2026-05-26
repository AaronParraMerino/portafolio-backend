<?php

namespace App\Services\api;

use App\Models\AdminEvento;
use App\Models\AdminEventoComunicacion;
use App\Models\AdminEventoHistorial;
use App\Models\AdminEventoPlantilla;
use App\Models\Experiencia;
use App\Models\Habilidad;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminEventoService
{
    public const PAGE_SIZE = 9;

    public function workspace(): array
    {
        $events = AdminEvento::query()
            ->withCount('comunicaciones')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id_evento')
            ->get()
            ->map(fn (AdminEvento $evento): array => $this->formatEvent($evento))
            ->values();

        $communications = AdminEventoComunicacion::query()
            ->with('evento:id_evento,titulo')
            ->orderByDesc('created_at')
            ->orderByDesc('id_comunicacion')
            ->get()
            ->map(fn (AdminEventoComunicacion $communication): array => $this->formatCommunication($communication))
            ->values();

        $templates = AdminEventoPlantilla::query()
            ->orderBy('titulo')
            ->get()
            ->map(fn (AdminEventoPlantilla $template): array => $this->formatTemplate($template))
            ->values();

        $history = AdminEventoHistorial::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id_historial')
            ->limit(500)
            ->get()
            ->map(fn (AdminEventoHistorial $item): array => $this->formatHistoryItem($item))
            ->values();

        return [
            'sourceReady' => true,
            'supportsMutations' => true,
            'pageSize' => self::PAGE_SIZE,
            'events' => $events,
            'communications' => $communications,
            'templates' => $templates,
            'history' => $history,
            'profileTargets' => $this->profileTargets(),
        ];
    }

    public function createEvent(array $data, int $actorId): AdminEvento
    {
        return DB::transaction(function () use ($data, $actorId): AdminEvento {
            $evento = AdminEvento::create($this->eventPayload($data, $actorId, true));

            $this->recordHistory(
                $actorId,
                'creacion',
                'evento',
                $evento->id_evento,
                'Evento creado',
                "Se creo el evento {$evento->titulo}.",
                $evento->tipo,
                $this->statusForHistory($evento->estado),
                $evento->titulo,
                $evento->channels ?? [],
                ['estado' => $evento->estado]
            );

            return $evento->fresh()->loadCount('comunicaciones');
        });
    }

    public function updateEvent(AdminEvento $evento, array $data, int $actorId): AdminEvento
    {
        return DB::transaction(function () use ($evento, $data, $actorId): AdminEvento {
            $previousStatus = $evento->estado;
            $evento->update($this->eventPayload($data, $actorId, false));
            $evento = $evento->fresh()->loadCount('comunicaciones');
            $action = $this->eventActionFromStatusChange($previousStatus, $evento->estado);

            $this->recordHistory(
                $actorId,
                $action,
                'evento',
                $evento->id_evento,
                $this->historyTitleForAction($action, 'Evento actualizado'),
                "Se actualizo el evento {$evento->titulo}.",
                $evento->tipo,
                $this->statusForHistory($evento->estado),
                $evento->titulo,
                $evento->channels ?? [],
                ['estado_anterior' => $previousStatus, 'estado' => $evento->estado]
            );

            return $evento;
        });
    }

    public function duplicateEvent(AdminEvento $evento, int $actorId): AdminEvento
    {
        return DB::transaction(function () use ($evento, $actorId): AdminEvento {
            $copy = $evento->replicate([
                'id_evento',
                'created_at',
                'updated_at',
            ]);
            $copy->titulo = $evento->titulo.' (copia)';
            $copy->estado = 'borrador';
            $copy->usuario_creador_id = $actorId;
            $copy->usuario_actualizador_id = $actorId;
            $copy->inscritos = 0;
            $copy->interesados = 0;
            $copy->espera = 0;
            $copy->asistieron = 0;
            $copy->no_asistieron = 0;
            $copy->save();

            $this->recordHistory(
                $actorId,
                'duplicado',
                'evento',
                $copy->id_evento,
                'Evento duplicado',
                "Se duplico el evento {$evento->titulo}.",
                $copy->tipo,
                'borrador',
                $copy->titulo,
                $copy->channels ?? [],
                ['evento_origen_id' => $evento->id_evento]
            );

            return $copy->fresh()->loadCount('comunicaciones');
        });
    }

    public function cancelEvent(AdminEvento $evento, int $actorId): AdminEvento
    {
        return $this->updateEvent($evento, ['estado' => 'cancelado'], $actorId);
    }

    public function createCommunication(array $data, int $actorId): AdminEventoComunicacion
    {
        return DB::transaction(function () use ($data, $actorId): AdminEventoComunicacion {
            $communication = AdminEventoComunicacion::create($this->communicationPayload($data, $actorId, true));
            $communication = $communication->fresh()->load('evento:id_evento,titulo');

            $this->recordHistoryForCommunication($actorId, $communication, 'creacion');

            return $communication;
        });
    }

    public function updateCommunication(AdminEventoComunicacion $communication, array $data, int $actorId): AdminEventoComunicacion
    {
        return DB::transaction(function () use ($communication, $data, $actorId): AdminEventoComunicacion {
            $previousStatus = $communication->estado;
            $communication->update($this->communicationPayload($data, $actorId, false));
            $communication = $communication->fresh()->load('evento:id_evento,titulo');
            $action = $this->communicationActionFromStatusChange($previousStatus, $communication->estado);

            $this->recordHistoryForCommunication($actorId, $communication, $action, [
                'estado_anterior' => $previousStatus,
                'estado' => $communication->estado,
            ]);

            return $communication;
        });
    }

    public function archiveCommunication(AdminEventoComunicacion $communication, int $actorId): AdminEventoComunicacion
    {
        return $this->updateCommunication($communication, ['estado' => 'archivado'], $actorId);
    }

    public function createTemplate(array $data, int $actorId): AdminEventoPlantilla
    {
        return DB::transaction(function () use ($data, $actorId): AdminEventoPlantilla {
            $template = AdminEventoPlantilla::create($this->templatePayload($data, $actorId, true));

            $this->recordHistory(
                $actorId,
                'creacion',
                'plantilla',
                $template->id_plantilla,
                'Plantilla creada',
                "Se creo la plantilla {$template->titulo}.",
                $template->tipo,
                'borrador',
                $template->titulo,
                $template->channels ?? [],
                ['plantilla_id' => $template->id_plantilla]
            );

            return $template->fresh();
        });
    }

    public function updateTemplate(AdminEventoPlantilla $template, array $data, int $actorId): AdminEventoPlantilla
    {
        return DB::transaction(function () use ($template, $data, $actorId): AdminEventoPlantilla {
            $template->update($this->templatePayload($data, $actorId, false));

            $this->recordHistory(
                $actorId,
                'edicion',
                'plantilla',
                $template->id_plantilla,
                'Plantilla actualizada',
                "Se actualizo la plantilla {$template->titulo}.",
                $template->tipo,
                'borrador',
                $template->titulo,
                $template->channels ?? [],
                ['plantilla_id' => $template->id_plantilla]
            );

            return $template->fresh();
        });
    }

    public function formatEvent(AdminEvento $evento): array
    {
        $targetSelections = $this->defaultTargetSelections($evento->target_selections ?? []);

        return [
            'id' => $evento->id_evento,
            'id_evento' => $evento->id_evento,
            'title' => $evento->titulo,
            'titulo' => $evento->titulo,
            'description' => $evento->descripcion,
            'descripcion' => $evento->descripcion,
            'type' => $evento->tipo,
            'tipo' => $evento->tipo,
            'status' => $evento->estado,
            'estado' => $evento->estado,
            'startsAt' => $this->formatDateTime($evento->fecha_inicio),
            'fecha_inicio' => $this->formatDateTime($evento->fecha_inicio),
            'endsAt' => $this->formatDateTime($evento->fecha_fin),
            'fecha_fin' => $this->formatDateTime($evento->fecha_fin),
            'sendAt' => $this->formatDateTime($evento->programado_para),
            'fecha_envio' => $this->formatDateTime($evento->programado_para),
            'date' => $evento->fecha_inicio?->format('Y-m-d'),
            'fecha' => $evento->fecha_inicio?->format('Y-m-d'),
            'time' => $evento->fecha_inicio?->format('H:i'),
            'hora' => $evento->fecha_inicio?->format('H:i'),
            'location' => $evento->ubicacion,
            'ubicacion' => $evento->ubicacion,
            'capacity' => (int) $evento->cupo,
            'cupo' => (int) $evento->cupo,
            'registered' => (int) $evento->inscritos,
            'inscritos' => (int) $evento->inscritos,
            'interested' => (int) $evento->interesados,
            'interesados' => (int) $evento->interesados,
            'waitlist' => (int) $evento->espera,
            'espera' => (int) $evento->espera,
            'attended' => (int) $evento->asistieron,
            'asistieron' => (int) $evento->asistieron,
            'missed' => (int) $evento->no_asistieron,
            'no_asistieron' => (int) $evento->no_asistieron,
            'communicationsCount' => (int) ($evento->comunicaciones_count ?? $evento->comunicaciones()->count()),
            'comunicaciones' => (int) ($evento->comunicaciones_count ?? $evento->comunicaciones()->count()),
            'targetMode' => $evento->target_mode,
            'target_mode' => $evento->target_mode,
            'channels' => $evento->channels ?? [],
            'canales' => $evento->channels ?? [],
            'segments' => $evento->segments ?? [],
            'segmentos' => $evento->segments ?? [],
            'targetSelections' => $targetSelections,
            'habilidades_tecnicas' => $targetSelections['technicalSkills'],
            'habilidades_blandas' => $targetSelections['softSkills'],
            'experiencia_academica' => $targetSelections['academicExperience'],
            'experiencia_laboral' => $targetSelections['workExperience'],
        ];
    }

    public function formatCommunication(AdminEventoComunicacion $communication): array
    {
        return [
            'id' => $communication->id_comunicacion,
            'id_comunicacion' => $communication->id_comunicacion,
            'eventId' => $communication->evento_id,
            'id_evento' => $communication->evento_id,
            'eventTitle' => $communication->evento?->titulo ?? 'Evento sin vincular',
            'titulo_evento' => $communication->evento?->titulo,
            'title' => $communication->titulo,
            'titulo' => $communication->titulo,
            'body' => $communication->cuerpo,
            'cuerpo' => $communication->cuerpo,
            'type' => $communication->tipo,
            'tipo' => $communication->tipo,
            'status' => $communication->estado,
            'estado' => $communication->estado,
            'urgency' => $communication->urgencia,
            'urgencia' => $communication->urgencia,
            'audience' => (int) $communication->destinatarios,
            'destinatarios' => (int) $communication->destinatarios,
            'date' => $this->formatDateTime($communication->programado_para)
                ?? $this->formatDateTime($communication->enviado_en)
                ?? $this->formatDateTime($communication->created_at),
            'scheduledAt' => $this->formatDateTime($communication->programado_para),
            'createdAt' => $this->formatDateTime($communication->created_at),
            'channels' => $communication->channels ?? [],
            'canales' => $communication->channels ?? [],
            'segments' => $communication->segments ?? $communication->audiences ?? [],
            'segmentos' => $communication->segments ?? $communication->audiences ?? [],
            'audiences' => $communication->audiences ?? [],
            'pinned' => (bool) $communication->pinned,
        ];
    }

    public function formatTemplate(AdminEventoPlantilla $template): array
    {
        return [
            'id' => $template->id_plantilla,
            'id_plantilla' => $template->id_plantilla,
            'title' => $template->titulo,
            'titulo' => $template->titulo,
            'body' => $template->cuerpo,
            'cuerpo' => $template->cuerpo,
            'type' => $template->tipo,
            'tipo' => $template->tipo,
            'channels' => $template->channels ?? [],
            'canales' => $template->channels ?? [],
            'used' => (int) $template->usadas,
            'usadas' => (int) $template->usadas,
            'payload' => $template->payload ?? [],
        ];
    }

    private function formatHistoryItem(AdminEventoHistorial $item): array
    {
        return [
            'id' => $item->id_historial,
            'id_historial' => $item->id_historial,
            'title' => $item->titulo,
            'titulo' => $item->titulo,
            'description' => $item->descripcion,
            'descripcion' => $item->descripcion,
            'type' => $item->tipo,
            'tipo' => $item->tipo,
            'status' => $item->estado,
            'estado' => $item->estado,
            'target' => $item->destino,
            'destino' => $item->destino,
            'date' => $this->formatDateTime($item->created_at),
            'fecha' => $this->formatDateTime($item->created_at),
            'channels' => $item->channels ?? [],
            'canales' => $item->channels ?? [],
            'metadata' => $item->metadata ?? [],
        ];
    }

    private function profileTargets(): array
    {
        return [
            'technicalSkills' => $this->skillTargets('tecnica'),
            'softSkills' => $this->skillTargets('blanda'),
            'academicExperience' => $this->experienceTargets('academica'),
            'workExperience' => $this->experienceTargets('laboral'),
        ];
    }

    private function skillTargets(string $type): array
    {
        return Habilidad::query()
            ->where('tipo', $type)
            ->whereRaw('estado = true')
            ->orderBy('nombre')
            ->pluck('nombre')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function experienceTargets(string $type): array
    {
        $items = Experiencia::query()
            ->where('tipo', $type)
            ->whereRaw('es_publico = true')
            ->get(['institucion', 'cargo']);

        return $items
            ->flatMap(fn (Experiencia $experience): array => [
                $experience->cargo,
                $experience->institucion,
            ])
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function eventPayload(array $data, int $actorId, bool $creating): array
    {
        $payload = [
            'usuario_actualizador_id' => $actorId,
        ];

        if ($creating) {
            $payload['usuario_creador_id'] = $actorId;
        }

        $payload = array_merge($payload, $this->onlyPresent([
            'titulo' => $data['titulo'] ?? $data['title'] ?? null,
            'descripcion' => $data['descripcion'] ?? $data['description'] ?? null,
            'tipo' => $data['tipo'] ?? $data['type'] ?? null,
            'estado' => $data['estado'] ?? $data['status'] ?? null,
            'fecha_inicio' => $data['fecha_inicio'] ?? $data['startsAt'] ?? null,
            'fecha_fin' => $data['fecha_fin'] ?? $data['endsAt'] ?? null,
            'programado_para' => $data['programado_para'] ?? $data['sendAt'] ?? null,
            'ubicacion' => $data['ubicacion'] ?? $data['location'] ?? null,
            'cupo' => $data['cupo'] ?? $data['capacity'] ?? null,
            'inscritos' => $data['inscritos'] ?? $data['registered'] ?? null,
            'interesados' => $data['interesados'] ?? $data['interested'] ?? null,
            'espera' => $data['espera'] ?? $data['waitlist'] ?? null,
            'asistieron' => $data['asistieron'] ?? $data['attended'] ?? null,
            'no_asistieron' => $data['no_asistieron'] ?? $data['missed'] ?? null,
            'target_mode' => $data['target_mode'] ?? $data['targetMode'] ?? null,
            'channels' => $data['channels'] ?? $data['canales'] ?? null,
            'segments' => $data['segments'] ?? $data['segmentos'] ?? null,
        ]));

        if ($creating || $this->hasAny($data, ['target_selections', 'targetSelections'])) {
            $payload['target_selections'] = $this->defaultTargetSelections(
                $data['target_selections']
                    ?? $data['targetSelections']
                    ?? []
            );
        }

        return $payload;
    }

    private function communicationPayload(array $data, int $actorId, bool $creating): array
    {
        $estado = $data['estado'] ?? $data['status'] ?? null;
        $programadoPara = $data['programado_para'] ?? $data['scheduledAt'] ?? $data['date'] ?? null;
        $enviadoEn = ($estado === 'enviado' && empty($data['enviado_en'])) ? now() : ($data['enviado_en'] ?? null);
        $audiences = $data['audiences'] ?? $data['audiencias'] ?? null;
        $segments = $data['segments'] ?? $data['segmentos'] ?? $audiences;

        $payload = [
            'usuario_actualizador_id' => $actorId,
        ];

        if ($creating) {
            $payload['usuario_creador_id'] = $actorId;
        }

        return array_merge($payload, $this->onlyPresent([
            'evento_id' => $data['evento_id'] ?? $data['eventId'] ?? null,
            'titulo' => $data['titulo'] ?? $data['title'] ?? null,
            'cuerpo' => $data['cuerpo'] ?? $data['body'] ?? null,
            'tipo' => $data['tipo'] ?? $data['type'] ?? null,
            'estado' => $estado,
            'urgencia' => $data['urgencia'] ?? $data['urgency'] ?? null,
            'destinatarios' => $this->communicationAudienceCount($data, $audiences, $creating),
            'programado_para' => $programadoPara,
            'enviado_en' => $enviadoEn,
            'audiences' => $audiences,
            'channels' => $data['channels'] ?? $data['canales'] ?? null,
            'segments' => $segments,
            'pinned' => $data['pinned'] ?? null,
        ]));
    }

    private function templatePayload(array $data, int $actorId, bool $creating): array
    {
        $payload = [
            'usuario_actualizador_id' => $actorId,
        ];

        if ($creating) {
            $payload['usuario_creador_id'] = $actorId;
        }

        $payload = array_merge($payload, $this->onlyPresent([
            'titulo' => $data['titulo'] ?? $data['title'] ?? $data['name'] ?? null,
            'cuerpo' => $data['cuerpo'] ?? $data['body'] ?? $data['descripcion'] ?? null,
            'tipo' => $data['tipo'] ?? $data['type'] ?? null,
            'channels' => $data['channels'] ?? $data['canales'] ?? null,
        ]));

        if ($creating || $this->hasAny($data, ['payload', 'audiences', 'segments', 'segmentos', 'channels', 'canales'])) {
            $payload['payload'] = $data['payload'] ?? [
                'audiences' => $data['audiences'] ?? null,
                'segments' => $data['segments'] ?? $data['segmentos'] ?? null,
                'channels' => $data['channels'] ?? $data['canales'] ?? null,
            ];
        }

        return $payload;
    }

    private function recordHistoryForCommunication(
        int $actorId,
        AdminEventoComunicacion $communication,
        string $action,
        array $metadata = []
    ): void {
        $this->recordHistory(
            $actorId,
            $action,
            'comunicacion',
            $communication->id_comunicacion,
            $this->historyTitleForAction($action, 'Comunicacion actualizada'),
            $communication->titulo,
            $communication->tipo,
            $communication->estado,
            $communication->evento?->titulo ?? 'Comunicado general',
            $communication->channels ?? [],
            $metadata
        );
    }

    private function recordHistory(
        int $actorId,
        string $action,
        string $entityType,
        int $entityId,
        string $title,
        ?string $description,
        string $type,
        string $status,
        ?string $target,
        array $channels,
        array $metadata = []
    ): void {
        AdminEventoHistorial::create([
            'usuario_actor_id' => $actorId,
            'accion' => $action,
            'entidad_tipo' => $entityType,
            'entidad_id' => $entityId,
            'titulo' => $title,
            'descripcion' => $description,
            'tipo' => $type,
            'estado' => $status,
            'destino' => $target,
            'channels' => $channels,
            'metadata' => $metadata,
        ]);
    }

    private function estimateAudience(array $audiences): int
    {
        if (in_array('admins', $audiences, true)) {
            return Usuario::query()->where('rol', 'admin')->count();
        }

        if (in_array('new_users', $audiences, true)) {
            return Usuario::query()->where('created_at', '>=', now()->subDays(30))->count();
        }

        if (in_array('portfolio_users', $audiences, true)) {
            return Usuario::query()->whereHas('perfil')->count();
        }

        return Usuario::query()->count();
    }

    private function defaultTargetSelections(array $selections): array
    {
        return [
            'technicalSkills' => array_values($selections['technicalSkills'] ?? $selections['habilidades_tecnicas'] ?? []),
            'softSkills' => array_values($selections['softSkills'] ?? $selections['habilidades_blandas'] ?? []),
            'academicExperience' => array_values($selections['academicExperience'] ?? $selections['experiencia_academica'] ?? []),
            'workExperience' => array_values($selections['workExperience'] ?? $selections['experiencia_laboral'] ?? []),
        ];
    }

    private function onlyPresent(array $data): array
    {
        return array_filter($data, fn ($value): bool => $value !== null);
    }

    private function hasAny(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }

        return false;
    }

    private function communicationAudienceCount(array $data, ?array $audiences, bool $creating): ?int
    {
        if (array_key_exists('destinatarios', $data)) {
            return (int) $data['destinatarios'];
        }

        if (array_key_exists('audience', $data)) {
            return (int) $data['audience'];
        }

        if ($creating || $audiences !== null) {
            return $this->estimateAudience($audiences ?? []);
        }

        return null;
    }

    private function formatDateTime($value): ?string
    {
        if (! $value) {
            return null;
        }

        return $value instanceof Carbon
            ? $value->format('Y-m-d\TH:i:s')
            : Carbon::parse($value)->format('Y-m-d\TH:i:s');
    }

    private function eventActionFromStatusChange(?string $previousStatus, string $status): string
    {
        if ($status === 'cancelado' && $previousStatus !== 'cancelado') {
            return 'cancelacion';
        }

        if ($status === 'programado' && $previousStatus !== 'programado') {
            return 'programacion';
        }

        if ($status === 'activo' && $previousStatus !== 'activo') {
            return 'envio';
        }

        return 'edicion';
    }

    private function communicationActionFromStatusChange(?string $previousStatus, string $status): string
    {
        if ($status === 'archivado' && $previousStatus !== 'archivado') {
            return 'archivado';
        }

        if ($status === 'programado' && $previousStatus !== 'programado') {
            return 'programacion';
        }

        if ($status === 'enviado' && $previousStatus !== 'enviado') {
            return 'envio';
        }

        return 'edicion';
    }

    private function historyTitleForAction(string $action, string $fallback): string
    {
        return [
            'creacion' => 'Registro creado',
            'edicion' => 'Registro actualizado',
            'duplicado' => 'Registro duplicado',
            'cancelacion' => 'Evento cancelado',
            'archivado' => 'Comunicacion archivada',
            'programacion' => 'Registro programado',
            'envio' => 'Registro enviado',
        ][$action] ?? $fallback;
    }

    private function statusForHistory(string $eventStatus): string
    {
        return match ($eventStatus) {
            'programado' => 'programado',
            'activo' => 'enviado',
            'cancelado' => 'archivado',
            default => 'borrador',
        };
    }
}
