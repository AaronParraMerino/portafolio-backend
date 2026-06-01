<?php

namespace App\Services\api;

use App\Models\AdminEvento;
use App\Models\AdminEventoAccion;
use App\Models\AdminEventoHistorial;
use App\Models\SolicitudPublicante;
use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminEventoService
{
    public const PAGE_SIZE = 9;
    public const MONTHLY_EVENT_LIMIT = 3;

    public function __construct(
        private readonly NotificacionService $notificacionService
    ) {
    }

    public function workspace(): array
    {
        return [
            'sourceReady' => true,
            'supportsMutations' => true,
            'pageSize' => self::PAGE_SIZE,
            'events' => $this->adminEvents()->all(),
            'publisherRequests' => $this->publisherRequests()->all(),
            'requests' => $this->publisherRequests()->all(),
            'communications' => [],
            'templates' => [],
            'history' => $this->history()->all(),
        ];
    }

    public function publisherRequests()
    {
        return SolicitudPublicante::query()
            ->with('usuario:id_usuario,nombre,apellido,correo,telefono,rol')
            ->orderByRaw("CASE estado WHEN 'pendiente' THEN 0 WHEN 'aprobada' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SolicitudPublicante $request): array => $this->formatPublisherRequest($request))
            ->values();
    }

    public function createPublisherRequest(Usuario $usuario, array $data): SolicitudPublicante
    {
        if ($usuario->rol === 'publicante') {
            throw new \RuntimeException('Tu cuenta ya tiene permisos de publicante.');
        }

        $hasPending = SolicitudPublicante::query()
            ->where('usuario_id', $usuario->id_usuario)
            ->where('estado', 'pendiente')
            ->exists();

        if ($hasPending) {
            throw new \RuntimeException('Ya tienes una solicitud pendiente de revision.');
        }

        return DB::transaction(function () use ($usuario, $data): SolicitudPublicante {
            $request = SolicitudPublicante::create([
                'usuario_id' => $usuario->id_usuario,
                'documento' => $data['documento'] ?? $data['documentId'],
                'telefono_actual' => $data['telefono_actual'] ?? $data['currentPhone'],
                'telefono_referencia' => $data['telefono_referencia'] ?? $data['referencePhone'],
                'correo_respaldo' => $data['correo_respaldo'] ?? $data['backupEmail'],
                'organizacion' => $data['organizacion'] ?? $data['organization'],
                'cargo' => $data['cargo'] ?? $data['role'],
                'motivo' => $data['motivo'] ?? $data['reason'],
                'experiencia' => $data['experiencia'] ?? $data['experience'],
                'enlaces' => $data['enlaces'] ?? $data['links'],
                'estado' => 'pendiente',
            ]);

            $this->recordHistory(
                $usuario->id_usuario,
                'solicitud_publicante',
                'solicitud',
                $request->id_solicitud,
                'Solicitud de publicante creada',
                'El usuario solicito permisos para publicar eventos.',
                'plataforma',
                'programado',
                trim($usuario->nombre.' '.$usuario->apellido),
                [],
                ['solicitud_id' => $request->id_solicitud]
            );

            return $request->fresh()->load('usuario:id_usuario,nombre,apellido,correo,telefono,rol');
        });
    }

    public function approvePublisherRequest(SolicitudPublicante $request, Usuario $admin, string $reason): SolicitudPublicante
    {
        if ($request->estado !== 'pendiente') {
            throw new \RuntimeException('La solicitud ya fue revisada.');
        }

        return DB::transaction(function () use ($request, $admin, $reason): SolicitudPublicante {
            $request->update([
                'admin_revisor_id' => $admin->id_usuario,
                'estado' => 'aprobada',
                'motivo_revision' => $reason,
                'revisada_en' => now(),
            ]);

            $request->usuario->update(['rol' => 'publicante']);

            $this->notifyUser(
                $admin->id_usuario,
                $request->usuario_id,
                'Solicitud de publicante aprobada',
                $reason,
                'actividad',
                'media',
                ['solicitud_publicante', 'aprobada']
            );

            $this->recordHistory(
                $admin->id_usuario,
                'aprobacion_solicitud',
                'solicitud',
                $request->id_solicitud,
                'Solicitud aprobada',
                $reason,
                'plataforma',
                'enviado',
                trim($request->usuario->nombre.' '.$request->usuario->apellido),
                ['inapp'],
                ['usuario_id' => $request->usuario_id]
            );

            return $request->fresh()->load('usuario:id_usuario,nombre,apellido,correo,telefono,rol');
        });
    }

    public function rejectPublisherRequest(SolicitudPublicante $request, Usuario $admin, string $reason): SolicitudPublicante
    {
        if ($request->estado !== 'pendiente') {
            throw new \RuntimeException('La solicitud ya fue revisada.');
        }

        return DB::transaction(function () use ($request, $admin, $reason): SolicitudPublicante {
            $request->update([
                'admin_revisor_id' => $admin->id_usuario,
                'estado' => 'rechazada',
                'motivo_revision' => $reason,
                'revisada_en' => now(),
            ]);

            $this->notifyUser(
                $admin->id_usuario,
                $request->usuario_id,
                'Solicitud de publicante rechazada',
                $reason,
                'actividad',
                'alta',
                ['solicitud_publicante', 'rechazada']
            );

            $this->recordHistory(
                $admin->id_usuario,
                'rechazo_solicitud',
                'solicitud',
                $request->id_solicitud,
                'Solicitud rechazada',
                $reason,
                'plataforma',
                'archivado',
                trim($request->usuario->nombre.' '.$request->usuario->apellido),
                ['inapp'],
                ['usuario_id' => $request->usuario_id]
            );

            return $request->fresh()->load('usuario:id_usuario,nombre,apellido,correo,telefono,rol');
        });
    }

    public function publisherEvents(Usuario $usuario)
    {
        return AdminEvento::query()
            ->where('usuario_creador_id', $usuario->id_usuario)
            ->where('estado', '!=', 'eliminado')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id_evento')
            ->get()
            ->map(fn (AdminEvento $event): array => $this->formatEvent($event))
            ->values();
    }

    public function adminEvents()
    {
        return AdminEvento::query()
            ->with('creador:id_usuario,nombre,apellido,correo,telefono,rol')
            ->where('estado', '!=', 'eliminado')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id_evento')
            ->get()
            ->map(fn (AdminEvento $event): array => $this->formatEvent($event))
            ->values();
    }

    public function createPublisherEvent(Usuario $usuario, array $data, ?UploadedFile $image = null): AdminEvento
    {
        if ($usuario->rol !== 'publicante') {
            throw new \RuntimeException('Solo usuarios con rol publicante pueden crear eventos.');
        }

        $startsAt = $this->parseEventStart($data);
        $this->ensureMonthlyLimit($usuario, $startsAt);

        return DB::transaction(function () use ($usuario, $data, $image): AdminEvento {
            $payload = $this->eventPayload($data, $usuario->id_usuario, true);
            $event = AdminEvento::create($payload);

            if ($image) {
                $this->storeCoverImage($event, $image);
            }

            $this->recordHistory(
                $usuario->id_usuario,
                'creacion_evento',
                'evento',
                $event->id_evento,
                'Evento creado por publicante',
                "Se creo el evento {$event->titulo}.",
                $event->tipo,
                $event->estado,
                $event->titulo,
                [],
                ['usuario_publicante_id' => $usuario->id_usuario]
            );

            return $event->fresh()->load('creador:id_usuario,nombre,apellido,correo,telefono,rol');
        });
    }

    public function updatePublisherEvent(Usuario $usuario, AdminEvento $event, array $data, ?UploadedFile $image = null): AdminEvento
    {
        if ($usuario->rol !== 'publicante' || (int) $event->usuario_creador_id !== (int) $usuario->id_usuario) {
            throw new \RuntimeException('No puedes modificar este evento.');
        }

        if ($event->estado === 'eliminado') {
            throw new \RuntimeException('No puedes modificar un evento eliminado.');
        }

        $startsAt = $this->parseEventStart($data, $event->fecha_inicio);
        $this->ensureMonthlyLimit($usuario, $startsAt, $event->id_evento);

        return DB::transaction(function () use ($usuario, $event, $data, $image): AdminEvento {
            $event->update($this->eventPayload($data, $usuario->id_usuario, false));

            if ($image) {
                $this->storeCoverImage($event, $image);
            } elseif (($data['imageUrl'] ?? null) === '' || ($data['imagen_url'] ?? null) === '') {
                $this->deleteCoverImage($event);
            }

            $this->recordHistory(
                $usuario->id_usuario,
                'edicion_evento',
                'evento',
                $event->id_evento,
                'Evento editado por publicante',
                "Se actualizo el evento {$event->titulo}.",
                $event->tipo,
                $event->estado,
                $event->titulo,
                [],
                ['usuario_publicante_id' => $usuario->id_usuario]
            );

            return $event->fresh()->load('creador:id_usuario,nombre,apellido,correo,telefono,rol');
        });
    }

    public function applyAdminEventAction(AdminEvento $event, Usuario $admin, string $action, string $reason): AdminEvento
    {
        $nextStatus = match ($action) {
            'activar' => 'activo',
            'pausar' => 'pausado',
            'suspender' => 'suspendido',
            'eliminar' => 'eliminado',
            default => throw new \InvalidArgumentException('Accion administrativa invalida.'),
        };

        return DB::transaction(function () use ($event, $admin, $action, $reason, $nextStatus): AdminEvento {
            $previousStatus = $event->estado;

            $event->update([
                'estado' => $nextStatus,
                'usuario_actualizador_id' => $admin->id_usuario,
            ]);

            AdminEventoAccion::create([
                'evento_id' => $event->id_evento,
                'admin_id' => $admin->id_usuario,
                'usuario_publicante_id' => $event->usuario_creador_id,
                'accion' => $action,
                'estado_anterior' => $previousStatus,
                'estado_nuevo' => $nextStatus,
                'motivo' => $reason,
                'metadata' => [
                    'evento' => $event->titulo,
                ],
            ]);

            $this->notifyUser(
                $admin->id_usuario,
                (int) $event->usuario_creador_id,
                'Accion administrativa sobre tu evento',
                "Evento: {$event->titulo}. Motivo: {$reason}",
                'actividad',
                in_array($action, ['suspender', 'eliminar'], true) ? 'alta' : 'media',
                ['evento', $action]
            );

            $this->recordHistory(
                $admin->id_usuario,
                $action,
                'evento',
                $event->id_evento,
                'Accion administrativa sobre evento',
                $reason,
                $event->tipo,
                $nextStatus,
                $event->titulo,
                ['inapp'],
                [
                    'estado_anterior' => $previousStatus,
                    'estado_nuevo' => $nextStatus,
                    'usuario_publicante_id' => $event->usuario_creador_id,
                ]
            );

            return $event->fresh()->load('creador:id_usuario,nombre,apellido,correo,telefono,rol');
        });
    }

    public function formatPublisherRequest(SolicitudPublicante $request): array
    {
        $user = $request->usuario;

        return [
            'id' => $request->id_solicitud,
            'id_solicitud' => $request->id_solicitud,
            'userId' => $request->usuario_id,
            'usuario_id' => $request->usuario_id,
            'name' => $user ? trim($user->nombre.' '.$user->apellido) : 'Usuario sin nombre',
            'nombre' => $user ? trim($user->nombre.' '.$user->apellido) : 'Usuario sin nombre',
            'email' => $user?->correo ?? $request->correo_respaldo,
            'correo' => $user?->correo ?? $request->correo_respaldo,
            'phone' => $request->telefono_actual,
            'telefono' => $request->telefono_actual,
            'documentId' => $request->documento,
            'documento' => $request->documento,
            'organization' => $request->organizacion,
            'organizacion' => $request->organizacion,
            'role' => $request->cargo,
            'cargo' => $request->cargo,
            'reason' => $request->motivo,
            'motivo' => $request->motivo,
            'experience' => $request->experiencia,
            'experiencia' => $request->experiencia,
            'links' => $request->enlaces,
            'enlaces' => $request->enlaces,
            'status' => $request->estado,
            'estado' => $request->estado,
            'revisionReason' => $request->motivo_revision,
            'motivo_revision' => $request->motivo_revision,
            'date' => $this->formatDateTime($request->created_at),
            'createdAt' => $this->formatDateTime($request->created_at),
        ];
    }

    public function formatEvent(AdminEvento $event): array
    {
        $creator = $event->relationLoaded('creador') ? $event->creador : $event->creador()->first();
        $imageUrl = $event->imagen_portada_url ?: ($event->imagen_portada_path ? Storage::disk('public')->url($event->imagen_portada_path) : null);

        return [
            'id' => $event->id_evento,
            'id_evento' => $event->id_evento,
            'title' => $event->titulo,
            'titulo' => $event->titulo,
            'description' => $event->descripcion,
            'descripcion' => $event->descripcion,
            'type' => $event->tipo,
            'tipo' => $event->tipo,
            'status' => $event->estado,
            'estado' => $event->estado,
            'startsAt' => $this->formatDateTime($event->fecha_inicio),
            'fecha_inicio' => $this->formatDateTime($event->fecha_inicio),
            'endsAt' => $this->formatDateTime($event->fecha_fin),
            'fecha_fin' => $this->formatDateTime($event->fecha_fin),
            'date' => $event->fecha_inicio?->format('Y-m-d'),
            'fecha' => $event->fecha_inicio?->format('Y-m-d'),
            'time' => $event->fecha_inicio?->format('H:i'),
            'hora' => $event->fecha_inicio?->format('H:i'),
            'location' => $event->ubicacion,
            'ubicacion' => $event->ubicacion,
            'capacity' => (int) $event->cupo,
            'cupo' => (int) $event->cupo,
            'registered' => (int) $event->inscritos,
            'inscritos' => (int) $event->inscritos,
            'imageUrl' => $imageUrl,
            'image_url' => $imageUrl,
            'imagen_url' => $imageUrl,
            'publisherId' => $event->usuario_creador_id,
            'publicante_id' => $event->usuario_creador_id,
            'usuario_creador_id' => $event->usuario_creador_id,
            'publisherName' => $creator ? trim($creator->nombre.' '.$creator->apellido) : 'Publicante sin nombre',
            'publisherEmail' => $creator?->correo ?? 'Sin correo',
            'creador' => $creator ? [
                'id' => $creator->id_usuario,
                'nombre' => trim($creator->nombre.' '.$creator->apellido),
                'correo' => $creator->correo,
                'rol' => $creator->rol,
            ] : null,
            'communicationsCount' => 0,
            'comunicaciones' => 0,
            'segments' => [],
            'channels' => [],
        ];
    }

    private function history()
    {
        return AdminEventoHistorial::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id_historial')
            ->limit(500)
            ->get()
            ->map(fn (AdminEventoHistorial $item): array => [
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
                'actor' => $item->metadata['actor'] ?? 'Sistema',
                'action' => $item->accion,
                'accion' => $item->accion,
                'reason' => $item->descripcion,
                'motivo' => $item->descripcion,
                'channels' => $item->channels ?? [],
                'canales' => $item->channels ?? [],
                'metadata' => $item->metadata ?? [],
            ])
            ->values();
    }

    private function eventPayload(array $data, int $actorId, bool $creating): array
    {
        $payload = [
            'usuario_actualizador_id' => $actorId,
            'titulo' => $data['titulo'] ?? $data['title'],
            'descripcion' => $data['descripcion'] ?? $data['description'] ?? null,
            'tipo' => $data['tipo'] ?? $data['type'] ?? 'taller',
            'estado' => $data['estado'] ?? $data['status'] ?? 'borrador',
            'fecha_inicio' => $data['fecha_inicio'] ?? $data['startsAt'] ?? null,
            'fecha_fin' => $data['fecha_fin'] ?? $data['endsAt'] ?? null,
            'ubicacion' => $data['ubicacion'] ?? $data['location'],
            'cupo' => $data['cupo'] ?? $data['capacity'] ?? 0,
        ];

        if ($creating) {
            $payload['usuario_creador_id'] = $actorId;
        }

        return array_filter($payload, fn ($value): bool => $value !== null);
    }

    private function parseEventStart(array $data, $fallback = null): Carbon
    {
        $value = $data['fecha_inicio'] ?? $data['startsAt'] ?? $fallback ?? now();

        return Carbon::parse($value);
    }

    private function ensureMonthlyLimit(Usuario $usuario, Carbon $startsAt, ?int $exceptEventId = null): void
    {
        $count = AdminEvento::query()
            ->where('usuario_creador_id', $usuario->id_usuario)
            ->where('estado', '!=', 'eliminado')
            ->whereBetween('fecha_inicio', [
                $startsAt->copy()->startOfMonth(),
                $startsAt->copy()->endOfMonth(),
            ])
            ->when($exceptEventId, fn ($query) => $query->where('id_evento', '!=', $exceptEventId))
            ->count();

        if ($count >= self::MONTHLY_EVENT_LIMIT) {
            throw new \RuntimeException('Ya alcanzaste el limite de 3 eventos para este mes.');
        }
    }

    private function storeCoverImage(AdminEvento $event, UploadedFile $image): void
    {
        $this->deleteCoverImage($event);

        $path = $image->store('eventos/portadas', 'public');

        $event->update([
            'imagen_portada_path' => $path,
            'imagen_portada_url' => Storage::disk('public')->url($path),
        ]);
    }

    private function deleteCoverImage(AdminEvento $event): void
    {
        if ($event->imagen_portada_path) {
            Storage::disk('public')->delete($event->imagen_portada_path);
        }

        $event->update([
            'imagen_portada_path' => null,
            'imagen_portada_url' => null,
        ]);
    }

    private function notifyUser(
        int $actorId,
        int $userId,
        string $title,
        string $content,
        string $type,
        string $urgency,
        array $segments
    ): void {
        $this->notificacionService->createAdminNotice($actorId, [
            'destinatarios' => [$userId],
            'titulo' => $title,
            'contenido' => $content,
            'tipo' => $type,
            'urgencia' => $urgency,
            'canales' => ['inapp'],
            'segmentos' => $segments,
        ]);
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

    private function formatDateTime($value): ?string
    {
        if (! $value) {
            return null;
        }

        return $value instanceof Carbon
            ? $value->format('Y-m-d\TH:i:s')
            : Carbon::parse($value)->format('Y-m-d\TH:i:s');
    }
}
