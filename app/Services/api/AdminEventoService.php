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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminEventoService
{
    public const PAGE_SIZE = 9;
    public const MONTHLY_EVENT_LIMIT = 3;

    public function __construct(
        private readonly AdminNotificacionGuardadoService $adminNotificacionGuardadoService,
        private readonly EventosNotificacionGuardadoService $eventosNotificacionGuardadoService,
        private readonly ProfileImageVariantService $profileImageVariants
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
            ->with([
                'usuario:id_usuario,nombre,apellido,correo,telefono,rol',
                'usuario.perfil:id_perfil,usuario_id,foto_perfil',
            ])
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

            return $request->fresh()->load([
                'usuario:id_usuario,nombre,apellido,correo,telefono,rol',
                'usuario.perfil:id_perfil,usuario_id,foto_perfil',
            ]);
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

            return $request->fresh()->load([
                'usuario:id_usuario,nombre,apellido,correo,telefono,rol',
                'usuario.perfil:id_perfil,usuario_id,foto_perfil',
            ]);
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

            return $request->fresh()->load([
                'usuario:id_usuario,nombre,apellido,correo,telefono,rol',
                'usuario.perfil:id_perfil,usuario_id,foto_perfil',
            ]);
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

            //seccion para notificar a usuarios inscritos
            $datosAnteriores = [
                'titulo' => $event->titulo,
                'descripcion' => $event->descripcion,
                'tipo' => $event->tipo,
                'estado' => $event->estado,
                'fecha_inicio' => $event->fecha_inicio,
                'fecha_fin' => $event->fecha_fin,
                'ubicacion' => $event->ubicacion,
                'cupo' => $event->cupo,
            ];
            //fin de seccion de notificar

            $event->update($this->eventPayload($data, $usuario->id_usuario, false));

            if ($image) {
                $this->storeCoverImage($event, $image);
            } elseif (($data['imageUrl'] ?? null) === '' || ($data['imagen_url'] ?? null) === '') {
                $this->deleteCoverImage($event);
            }

            //seccion para notificar a usuarios inscritos
            $event->refresh();

            $this->notificarCambiosEventoInscrito(
                usuario: $usuario,
                event: $event,
                datosAnteriores: $datosAnteriores
            );
            //fin de seccion de notificar

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

            //seccion para notificar a usuarios inscritos
            $event->refresh();

            $this->notificarCambioEstadoEventoInscrito(
                usuario: $admin,
                event: $event,
                estadoAnterior: $previousStatus
            );
            //fin de seccion de notificar

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
        $avatarUrl = $this->profileImageVariants->getVariantUrl($user?->perfil?->foto_perfil, 'thumb')
            ?? $user?->perfil?->foto_perfil;
        $fullName = $user ? trim($user->nombre.' '.$user->apellido) : 'Usuario sin nombre';

        return [
            'id' => $request->id_solicitud,
            'id_solicitud' => $request->id_solicitud,
            'userId' => $request->usuario_id,
            'usuario_id' => $request->usuario_id,
            'name' => $fullName,
            'nombre' => $fullName,
            'email' => $user?->correo ?? $request->correo_respaldo,
            'correo' => $user?->correo ?? $request->correo_respaldo,
            'phone' => $request->telefono_actual,
            'telefono' => $request->telefono_actual,
            'fotoPerfilThumbUrl' => $avatarUrl,
            'foto_perfil_thumb_url' => $avatarUrl,
            'avatarUrl' => $avatarUrl,
            'usuario' => $user ? [
                'id' => $user->id_usuario,
                'id_usuario' => $user->id_usuario,
                'nombre' => $fullName,
                'correo' => $user->correo,
                'telefono' => $user->telefono,
                'rol' => $user->rol,
                'fotoPerfilThumbUrl' => $avatarUrl,
                'foto_perfil_thumb_url' => $avatarUrl,
            ] : null,
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
        $imageUrl = $this->resolveCoverImageUrl($event);

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
            'sendAt' => $this->formatDateTime($event->programado_para),
            'programado_para' => $this->formatDateTime($event->programado_para),
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
            'targetMode' => $event->target_mode ?? 'all_users',
            'target_mode' => $event->target_mode ?? 'all_users',
            'targetSelections' => $event->target_selections ?? [],
            'target_selections' => $event->target_selections ?? [],
            'segments' => $event->segments ?? [],
            'segmentos' => $event->segments ?? [],
            'channels' => $event->channels ?? [],
            'canales' => $event->channels ?? [],
        ];
    }

    private function history()
    {
        return AdminEventoHistorial::query()
            ->with('actor:id_usuario,nombre,apellido,correo,rol')
            ->orderByDesc('created_at')
            ->orderByDesc('id_historial')
            ->limit(500)
            ->get()
            ->map(function (AdminEventoHistorial $item): array {
                $actorName = $item->actor
                    ? trim($item->actor->nombre.' '.$item->actor->apellido)
                    : ($item->metadata['actor'] ?? 'Sistema');

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
                    'actor' => $actorName ?: 'Usuario sin nombre',
                    'actorId' => $item->usuario_actor_id,
                    'actor_id' => $item->usuario_actor_id,
                    'actorRole' => $item->actor?->rol,
                    'actor_role' => $item->actor?->rol,
                    'actorEmail' => $item->actor?->correo,
                    'actor_email' => $item->actor?->correo,
                    'action' => $item->accion,
                    'accion' => $item->accion,
                    'reason' => $item->descripcion,
                    'motivo' => $item->descripcion,
                    'channels' => $item->channels ?? [],
                    'canales' => $item->channels ?? [],
                    'metadata' => $item->metadata ?? [],
                ];
            })
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
            'programado_para' => $data['programado_para'] ?? $data['sendAt'] ?? null,
            'ubicacion' => $data['ubicacion'] ?? $data['location'],
            'cupo' => $data['cupo'] ?? $data['capacity'] ?? 0,
            'target_mode' => $data['target_mode'] ?? $data['targetMode'] ?? 'all_users',
        ];

        $targetSelections = $this->normalizeTargetSelections($data['targetSelections'] ?? $data['target_selections'] ?? []);
        $payload['target_selections'] = $targetSelections;
        $payload['segments'] = $this->buildSegments($data['segments'] ?? $data['segmentos'] ?? [], $targetSelections);

        if ($creating) {
            $payload['usuario_creador_id'] = $actorId;
        }

        return array_filter(
            $payload,
            fn ($value, string $key): bool => $value !== null || $key === 'programado_para',
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function normalizeTargetSelections($value): array
    {
        $source = is_array($value) ? $value : [];

        return [
            'technicalSkills' => $this->normalizeStringList($source['technicalSkills'] ?? []),
            'softSkills' => $this->normalizeStringList($source['softSkills'] ?? []),
            'academicExperience' => $this->normalizeStringList($source['academicExperience'] ?? []),
            'workExperience' => $this->normalizeStringList($source['workExperience'] ?? []),
        ];
    }

    private function buildSegments($segments, array $targetSelections): array
    {
        if (is_array($segments) && $this->isAssociativeArray($segments)) {
            return $segments;
        }

        $result = [
            'habilidades' => [
                'tecnicas' => [
                    'items' => $targetSelections['technicalSkills'],
                    'niveles' => [],
                ],
                'blandas' => [
                    'items' => $targetSelections['softSkills'],
                    'niveles' => [],
                ],
            ],
            'experiencia' => [
                ...array_map(
                    fn (string $cargo): array => ['cargo' => $cargo, 'tipos' => ['academica']],
                    $targetSelections['academicExperience']
                ),
                ...array_map(
                    fn (string $cargo): array => ['cargo' => $cargo, 'tipos' => ['laboral']],
                    $targetSelections['workExperience']
                ),
            ],
        ];

        return $this->removeEmptyArrays($result);
    }

    private function normalizeStringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($item): string => trim((string) $item),
            $value
        ))));
    }

    private function isAssociativeArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function removeEmptyArrays(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $item = $this->removeEmptyArrays($item);
            }

            if ($item !== [] && $item !== null && $item !== '') {
                $result[$key] = $item;
            }
        }

        return $result;
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

        $upload = $this->storeCoverImageInSupabase($event, $image)
            ?? $this->storeCoverImageLocally($image);

        $event->update([
            'imagen_portada_path' => $upload['path'],
            'imagen_portada_url' => $upload['url'],
        ]);
    }

    private function deleteCoverImage(AdminEvento $event): void
    {
        if ($event->imagen_portada_path) {
            if ($this->isSupabaseCoverImage($event->imagen_portada_path, $event->imagen_portada_url)) {
                $this->deleteCoverImageFromSupabase($event->imagen_portada_path);
            } else {
                Storage::disk('public')->delete($event->imagen_portada_path);
            }
        }

        $event->update([
            'imagen_portada_path' => null,
            'imagen_portada_url' => null,
        ]);
    }

    private function storeCoverImageInSupabase(AdminEvento $event, UploadedFile $image): ?array
    {
        $bucket = trim((string) env('SUPABASE_BUCKET'), '/');
        $urlBase = rtrim((string) env('SUPABASE_URL'), '/');
        $key = (string) env('SUPABASE_KEY');

        if ($bucket === '' || $urlBase === '' || $key === '') {
            return null;
        }

        $extension = $image->getClientOriginalExtension() ?: $image->extension() ?: 'jpg';
        $path = 'events/evento-' . $event->id_evento . '-' . Str::uuid() . '.' . strtolower($extension);
        $content = file_get_contents($image->getRealPath());

        if ($content === false) {
            return null;
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $urlBase . '/storage/v1/object/' . $bucket . '/' . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'apikey: ' . $key,
                'Content-Type: ' . ($image->getMimeType() ?: 'application/octet-stream'),
            ],
        ]);

        curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || $status >= 400) {
            Log::warning('No se pudo subir la portada del evento a Supabase Storage; se usara storage local.', [
                'evento_id' => $event->id_evento,
                'status' => $status,
                'error' => $error,
            ]);

            return null;
        }

        return [
            'path' => $path,
            'url' => $urlBase . '/storage/v1/object/public/' . $bucket . '/' . $path,
        ];
    }

    private function storeCoverImageLocally(UploadedFile $image): array
    {
        $path = $image->store('eventos/portadas', 'public');

        return [
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ];
    }

    private function deleteCoverImageFromSupabase(string $path): void
    {
        $bucket = trim((string) env('SUPABASE_BUCKET'), '/');
        $urlBase = rtrim((string) env('SUPABASE_URL'), '/');
        $key = (string) env('SUPABASE_KEY');

        if ($bucket === '' || $urlBase === '' || $key === '') {
            return;
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $urlBase . '/storage/v1/object/' . $bucket . '/' . ltrim($path, '/'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'apikey: ' . $key,
            ],
        ]);

        curl_exec($ch);
        curl_close($ch);
    }

    private function resolveCoverImageUrl(AdminEvento $event): ?string
    {
        if ($event->imagen_portada_url) {
            return $event->imagen_portada_url;
        }

        if (! $event->imagen_portada_path) {
            return null;
        }

        if ($this->isSupabaseCoverImage($event->imagen_portada_path, null)) {
            $bucket = trim((string) env('SUPABASE_BUCKET'), '/');
            $urlBase = rtrim((string) env('SUPABASE_URL'), '/');

            if ($bucket !== '' && $urlBase !== '') {
                return $urlBase . '/storage/v1/object/public/' . $bucket . '/' . ltrim($event->imagen_portada_path, '/');
            }
        }

        return Storage::disk('public')->url($event->imagen_portada_path);
    }

    private function isSupabaseCoverImage(?string $path, ?string $url): bool
    {
        if ($path && str_starts_with(ltrim($path, '/'), 'events/')) {
            return true;
        }

        $urlBase = rtrim((string) env('SUPABASE_URL'), '/');

        return $url && $urlBase !== '' && str_starts_with($url, $urlBase . '/storage/v1/object/public/');
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
        $this->adminNotificacionGuardadoService->createAdminNotice($actorId, [
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


    //seccion para notificar a usuarios inscritos

    /**
     * Notifica cambios importantes de un evento inscrito
     */
    private function notificarCambiosEventoInscrito(
        Usuario $usuario,
        AdminEvento $event,
        array $datosAnteriores
    ): void {
        try {
            $estadoAnterior = (string) ($datosAnteriores['estado'] ?? '');
            $estadoNuevo = (string) $event->estado;

            if ($estadoAnterior !== $estadoNuevo) {
                $this->notificarCambioEstadoEventoInscrito(
                    usuario: $usuario,
                    event: $event,
                    estadoAnterior: $estadoAnterior
                );

                return;
            }

            $cambioFecha = $this->fechaEventoCambio(
                $datosAnteriores['fecha_inicio'] ?? null,
                $event->fecha_inicio
            ) || $this->fechaEventoCambio(
                $datosAnteriores['fecha_fin'] ?? null,
                $event->fecha_fin
            );

            $cambioUbicacion = $this->textoEventoCambio(
                $datosAnteriores['ubicacion'] ?? null,
                $event->ubicacion
            );

            if ($cambioFecha) {
                $this->eventosNotificacionGuardadoService->notificarEventoFechaActualizadaInscrito(
                    evento: $event,
                    actorId: (int) $usuario->id_usuario
                );
            }

            if ($cambioUbicacion) {
                $this->eventosNotificacionGuardadoService->notificarEventoUbicacionActualizadaInscrito(
                    evento: $event,
                    actorId: (int) $usuario->id_usuario
                );
            }

            if (! $cambioFecha && ! $cambioUbicacion && $this->hayCambioGeneralEventoInscrito($event, $datosAnteriores)) {
                $this->eventosNotificacionGuardadoService->notificarEventoActualizadoInscrito(
                    evento: $event,
                    actorId: (int) $usuario->id_usuario
                );
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo notificar cambios del evento inscrito', [
                'evento_id' => $event->id_evento,
                'usuario_actor_id' => $usuario->id_usuario,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifica cambio de estado de un evento inscrito
     */
    private function notificarCambioEstadoEventoInscrito(
        Usuario $usuario,
        AdminEvento $event,
        string $estadoAnterior
    ): void {
        try {
            $estadoNuevo = (string) $event->estado;

            if ($estadoAnterior === $estadoNuevo) {
                return;
            }

            if (in_array($estadoNuevo, ['borrador','pausado', 'suspendido', 'eliminado'], true)) {
                    $this->eventosNotificacionGuardadoService->notificarEventoNoDisponibleInscrito(
                    evento: $event,
                    actorId: (int) $usuario->id_usuario,
                    estadoNuevo: $estadoNuevo
                );

                return;
            }

            if ($estadoNuevo === 'activo' && in_array($estadoAnterior, ['pausado', 'suspendido', 'eliminado', 'borrador'], true)) {
                $this->eventosNotificacionGuardadoService->notificarEventoReactivadoInscrito(
                    evento: $event,
                    actorId: (int) $usuario->id_usuario
                );
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo notificar cambio de estado del evento inscrito', [
                'evento_id' => $event->id_evento,
                'usuario_actor_id' => $usuario->id_usuario,
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo' => $event->estado,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verifica si cambio una fecha del evento
     */
    private function fechaEventoCambio($anterior, $actual): bool
    {
        if (! $anterior && ! $actual) {
            return false;
        }

        if (! $anterior || ! $actual) {
            return true;
        }

        return Carbon::parse($anterior)->toDateTimeString() !== Carbon::parse($actual)->toDateTimeString();
    }

    /**
     * Verifica si cambio un texto del evento
     */
    private function textoEventoCambio($anterior, $actual): bool
    {
        return trim((string) $anterior) !== trim((string) $actual);
    }

    /**
     * Verifica si hubo otro cambio general importante
     */
    private function hayCambioGeneralEventoInscrito(AdminEvento $event, array $datosAnteriores): bool
    {
        return $this->textoEventoCambio($datosAnteriores['titulo'] ?? null, $event->titulo)
            || $this->textoEventoCambio($datosAnteriores['descripcion'] ?? null, $event->descripcion)
            || $this->textoEventoCambio($datosAnteriores['tipo'] ?? null, $event->tipo)
            || (int) ($datosAnteriores['cupo'] ?? 0) !== (int) $event->cupo;
    }

    //fin de seccion de notificar

}
