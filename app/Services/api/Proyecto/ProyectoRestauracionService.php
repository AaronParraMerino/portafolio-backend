<?php

namespace App\Services\api\Proyecto;

use App\Models\Notificacion;
use App\Models\NotificacionUsuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProyectoRestauracionService
{
    public function __construct(
        private readonly ProyectoCicloVidaService $proyectoCicloVidaService,
    ) {}

    public function restore(int $userId, int $projectId, ?int $requestNotificationId = null): array
    {
        $project = $this->deletedProject($projectId);
        if (! $project) {
            return ['status' => 'not_found'];
        }
        if (! $this->canRestore($userId, $projectId)) {
            return ['status' => 'forbidden'];
        }

        DB::transaction(function () use ($userId, $projectId, $project, $requestNotificationId) {
            DB::table('proyectos')->where('id_proyecto', $projectId)->update([
                'deleted_at' => null,
                'updated_at' => now(),
            ]);

            $this->ensureValidatedParticipation($userId, $projectId, true);
            $this->closePendingRequests($projectId, $userId, $requestNotificationId);
            $this->recordAudit($userId, $projectId, (string) $project->titulo, 'proyecto_restaurado');
            $this->notifyParticipants(
                $projectId,
                $userId,
                'project_restored',
                'El proyecto "'.$project->titulo.'" fue restablecido.'
            );
        });

        return ['status' => 'success', 'id_proyecto' => $projectId];
    }

    public function requestRestore(int $userId, int $projectId, ?string $message = null): array
    {
        $project = $this->deletedProject($projectId);
        if (! $project) {
            return ['status' => 'not_found'];
        }
        if (! $this->hasValidatedRepositoryRelation($userId, $projectId)) {
            return ['status' => 'forbidden'];
        }
        if ($this->canRestore($userId, $projectId)) {
            return ['status' => 'owner_can_restore'];
        }

        $latest = $this->latestRequest($userId, $projectId);
        if (($latest->accion_estado ?? null) === 'pendiente') {
            return ['status' => 'pending', 'id_notificacion' => (int) $latest->id_notificacion];
        }
        if (($latest->accion_disponible_nuevamente_at ?? null) && now()->lt($latest->accion_disponible_nuevamente_at)) {
            return [
                'status' => 'cooldown',
                'available_at' => $latest->accion_disponible_nuevamente_at,
            ];
        }

        $owners = $this->validatedOwnerIds($projectId, [$userId]);
        if ($owners->isEmpty()) {
            return ['status' => 'no_owners'];
        }

        $notificationId = DB::transaction(function () use ($userId, $projectId, $project, $message, $owners) {
            $this->ensureValidatedParticipation($userId, $projectId, false);

            $notification = Notificacion::create([
                'id_usuario_actor' => $userId,
                'modulo' => 'proyectos',
                'contexto_tipo' => 'proyecto',
                'contexto_referencia' => 'proyecto_'.$projectId,
                'grupo_titulo' => $project->titulo,
                'tipo' => 'project_restore_request',
                'mensaje' => trim((string) $message) !== ''
                    ? trim((string) $message)
                    : 'Se solicito restablecer el proyecto "'.$project->titulo.'".',
                'accion_estado' => 'pendiente',
                'metadata' => ['id_proyecto' => $projectId],
            ]);

            foreach ($owners as $ownerId) {
                NotificacionUsuario::create([
                    'id_notificacion' => $notification->id_notificacion,
                    'id_usuario' => $ownerId,
                    'leido_en' => null,
                ]);
            }

            $this->recordAudit($userId, $projectId, (string) $project->titulo, 'solicitud_restauracion_creada', [
                'id_notificacion' => $notification->id_notificacion,
                'destinatarios' => $owners->all(),
            ]);

            return (int) $notification->id_notificacion;
        });

        return ['status' => 'success', 'id_notificacion' => $notificationId, 'destinatarios' => $owners->count()];
    }

    public function respond(int $userId, int $projectId, int $notificationId, string $decision, ?string $response = null): array
    {
        if (! in_array($decision, ['aprobar', 'rechazar'], true)) {
            return ['status' => 'invalid_decision'];
        }
        if (! $this->isValidatedRepositoryOwner($userId, $projectId)) {
            return ['status' => 'forbidden'];
        }

        $notification = DB::table('notificaciones')
            ->where('id_notificacion', $notificationId)
            ->where('tipo', 'project_restore_request')
            ->where('contexto_referencia', 'proyecto_'.$projectId)
            ->first();

        if (! $notification) {
            return ['status' => 'not_found'];
        }
        if (($notification->accion_estado ?? null) !== 'pendiente') {
            return ['status' => 'already_resolved'];
        }

        if ($decision === 'aprobar') {
            $result = $this->restore($userId, $projectId, $notificationId);
            if (($result['status'] ?? null) !== 'success') {
                return $result;
            }

            return ['status' => 'approved', 'id_proyecto' => $projectId];
        }

        DB::transaction(function () use ($userId, $projectId, $notificationId, $notification, $response) {
            DB::table('notificaciones')->where('id_notificacion', $notificationId)->update([
                'accion_estado' => 'rechazada',
                'accion_respuesta' => $response,
                'accion_respuesta_usuario_id' => $userId,
                'accion_respondida_at' => now(),
                'accion_disponible_nuevamente_at' => now()->addDays(15),
                'updated_at' => now(),
            ]);

            $this->notifyUsers(
                collect([(int) $notification->id_usuario_actor]),
                $userId,
                $projectId,
                (string) $notification->grupo_titulo,
                'project_restore_request_rejected',
                'La solicitud para restablecer "'.$notification->grupo_titulo.'" fue rechazada.'
            );
            $this->recordAudit($userId, $projectId, (string) $notification->grupo_titulo, 'solicitud_restauracion_rechazada', [
                'id_notificacion' => $notificationId,
            ]);
        });

        return ['status' => 'rejected', 'available_at' => now()->addDays(15)];
    }

    public function releaseRepository(
        int $userId,
        int $projectId,
        int $projectRepositoryId,
        ?string $confirmationTitle = null
    ): array {
        $project = $this->deletedProject($projectId);
        if (! $project) {
            return ['status' => 'not_found'];
        }

        $repository = DB::table('proyecto_repositorios as pr')
            ->join('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->where('pr.id_proyecto_repositorio', $projectRepositoryId)
            ->where('pr.id_proyecto', $projectId)
            ->whereNull('pr.deleted_at')
            ->first(['pr.id_proyecto_repositorio', 'pr.nombre', 'rg.id_repositorio_github']);

        if (! $repository) {
            return ['status' => 'repository_not_found'];
        }
        if (! $this->isValidatedRemoteOwner($userId, (int) $repository->id_repositorio_github)) {
            return ['status' => 'forbidden'];
        }

        $repositoryCount = DB::table('proyecto_repositorios')
            ->where('id_proyecto', $projectId)
            ->whereNull('deleted_at')
            ->count();
        $lastRepository = $repositoryCount === 1;

        if ($lastRepository && ! hash_equals((string) $project->titulo, trim((string) $confirmationTitle))) {
            return [
                'status' => 'confirmation_required',
                'ultimo_repositorio' => true,
                'titulo' => (string) $project->titulo,
            ];
        }

        if ($lastRepository) {
            $deleted = $this->proyectoCicloVidaService->permanentlyDeleteDeletedProject(
                $userId,
                $projectId,
                $confirmationTitle,
                'eliminacion_permanente_ultimo_repositorio',
                $projectRepositoryId
            );

            return [
                'status' => ($deleted['status'] ?? null) === 'permanently_deleted'
                    ? 'released_and_project_deleted'
                    : ($deleted['status'] ?? 'error'),
                'ultimo_repositorio' => true,
            ];
        }

        DB::transaction(function () use ($userId, $projectId, $projectRepositoryId, $repository, $project, $lastRepository) {
            DB::table('participacion_repositorios')
                ->where('id_proyecto_repositorio', $projectRepositoryId)
                ->delete();

            DB::table('proyecto_repositorios')
                ->where('id_proyecto_repositorio', $projectRepositoryId)
                ->update(['id_proyecto' => null, 'updated_at' => now()]);

            $this->recordAudit($userId, $projectId, (string) $project->titulo, 'repositorio_liberado', [
                'id_proyecto_repositorio' => $projectRepositoryId,
                'nombre_repositorio' => $repository->nombre,
                'ultimo_repositorio' => $lastRepository,
            ]);
        });

        return ['status' => 'released', 'ultimo_repositorio' => false];
    }

    private function ensureValidatedParticipation(int $userId, int $projectId, bool $owner): int
    {
        $participation = DB::table('participaciones')
            ->where('id_usuario', $userId)
            ->where('id_proyecto', $projectId)
            ->first();
        $data = [
            'participacion_validada' => 'true',
            'es_propietario' => ($owner || $this->truthy($participation->es_propietario ?? false))
                ? 'true'
                : 'false',
            'estado_participacion' => 'activo',
            'deleted_at' => null,
            'updated_at' => now(),
        ];

        if ($participation) {
            DB::table('participaciones')->where('id_participacion', $participation->id_participacion)->update($data);
            $participationId = (int) $participation->id_participacion;
        } else {
            $participationId = (int) DB::table('participaciones')->insertGetId([
                'id_usuario' => $userId,
                'id_proyecto' => $projectId,
                'rol' => $owner ? 'propietario' : 'colaborador',
                'visibilidad' => 'publico',
                'created_at' => now(),
                ...$data,
            ], 'id_participacion');
        }

        $validatedRepos = DB::table('proyecto_repositorios as pr')
            ->join('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->join('usuario_repositorio_validaciones as urv', function ($join) use ($userId) {
                $join->on('urv.id_repositorio_github', '=', 'rg.id_repositorio_github')
                    ->where('urv.id_usuario', '=', $userId)
                    ->whereRaw('urv.validado = TRUE');
            })
            ->where('pr.id_proyecto', $projectId)
            ->whereNull('pr.deleted_at')
            ->get(['pr.id_proyecto_repositorio', 'urv.es_propietario']);

        foreach ($validatedRepos as $repo) {
            DB::table('participacion_repositorios')->updateOrInsert(
                [
                    'id_participacion' => $participationId,
                    'id_proyecto_repositorio' => $repo->id_proyecto_repositorio,
                ],
                [
                    'validado' => 'true',
                    'es_propietario' => $this->truthy($repo->es_propietario ?? false) ? 'true' : 'false',
                    'validado_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        return $participationId;
    }

    private function closePendingRequests(int $projectId, int $userId, ?int $approvedRequestId): void
    {
        DB::table('notificaciones')
            ->where('tipo', 'project_restore_request')
            ->where('contexto_referencia', 'proyecto_'.$projectId)
            ->where('accion_estado', 'pendiente')
            ->update([
                'accion_estado' => DB::raw('CASE WHEN id_notificacion = '.(int) ($approvedRequestId ?? 0)." THEN 'aprobada' ELSE 'cancelada' END"),
                'accion_respuesta_usuario_id' => $userId,
                'accion_respondida_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function notifyParticipants(int $projectId, int $actorId, string $type, string $message): void
    {
        $users = DB::table('participaciones')
            ->where('id_proyecto', $projectId)
            ->whereNull('deleted_at')
            ->where('id_usuario', '<>', $actorId)
            ->pluck('id_usuario');
        $title = (string) DB::table('proyectos')->where('id_proyecto', $projectId)->value('titulo');
        $this->notifyUsers($users, $actorId, $projectId, $title, $type, $message);
    }

    private function notifyUsers(Collection $users, int $actorId, int $projectId, string $title, string $type, string $message): void
    {
        $users = $users->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($users->isEmpty()) {
            return;
        }

        $notification = Notificacion::create([
            'id_usuario_actor' => $actorId,
            'modulo' => 'proyectos',
            'contexto_tipo' => 'proyecto',
            'contexto_referencia' => 'proyecto_'.$projectId,
            'grupo_titulo' => $title,
            'tipo' => $type,
            'mensaje' => $message,
        ]);
        foreach ($users as $userId) {
            NotificacionUsuario::create([
                'id_notificacion' => $notification->id_notificacion,
                'id_usuario' => $userId,
                'leido_en' => null,
            ]);
        }
    }

    private function validatedOwnerIds(int $projectId, array $exclude = []): Collection
    {
        return DB::table('proyecto_repositorios as pr')
            ->join('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->join('usuario_repositorio_validaciones as urv', 'urv.id_repositorio_github', '=', 'rg.id_repositorio_github')
            ->where('pr.id_proyecto', $projectId)
            ->whereNull('pr.deleted_at')
            ->whereRaw('urv.validado = TRUE')
            ->where(function ($query) {
                $query->whereRaw('urv.es_propietario = TRUE')->orWhere('urv.relacion_github', 'owner');
            })
            ->when($exclude !== [], fn ($query) => $query->whereNotIn('urv.id_usuario', $exclude))
            ->pluck('urv.id_usuario')
            ->unique()
            ->values();
    }

    private function isValidatedRepositoryOwner(int $userId, int $projectId): bool
    {
        return $this->validatedOwnerIds($projectId)->contains($userId);
    }

    private function canRestore(int $userId, int $projectId): bool
    {
        $owners = $this->validatedOwnerIds($projectId);

        return $owners->contains($userId)
            || ($owners->isEmpty() && $this->hasValidatedRepositoryRelation($userId, $projectId));
    }

    private function isValidatedRemoteOwner(int $userId, int $remoteId): bool
    {
        return DB::table('usuario_repositorio_validaciones')
            ->where('id_usuario', $userId)
            ->where('id_repositorio_github', $remoteId)
            ->whereRaw('validado = TRUE')
            ->where(function ($query) {
                $query->whereRaw('es_propietario = TRUE')->orWhere('relacion_github', 'owner');
            })
            ->exists();
    }

    private function hasValidatedRepositoryRelation(int $userId, int $projectId): bool
    {
        return DB::table('proyecto_repositorios as pr')
            ->join('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->join('usuario_repositorio_validaciones as urv', 'urv.id_repositorio_github', '=', 'rg.id_repositorio_github')
            ->where('pr.id_proyecto', $projectId)
            ->where('urv.id_usuario', $userId)
            ->whereNull('pr.deleted_at')
            ->whereRaw('urv.validado = TRUE')
            ->exists();
    }

    private function latestRequest(int $userId, int $projectId): ?object
    {
        return DB::table('notificaciones')
            ->where('id_usuario_actor', $userId)
            ->where('tipo', 'project_restore_request')
            ->where('contexto_referencia', 'proyecto_'.$projectId)
            ->orderByDesc('created_at')
            ->first();
    }

    private function deletedProject(int $projectId): ?object
    {
        return DB::table('proyectos')
            ->where('id_proyecto', $projectId)
            ->whereNotNull('deleted_at')
            ->first(['id_proyecto', 'titulo']);
    }

    private function recordAudit(int $userId, int $projectId, string $title, string $action, array $metadata = []): void
    {
        DB::table('bitacoras')->insert([
            'usuario_id' => $userId,
            'usuario_referencia_id' => $userId,
            'accion' => $action,
            'descripcion' => json_encode(['titulo_proyecto' => $title, ...$metadata], JSON_UNESCAPED_UNICODE),
            'tabla_afectada' => 'proyectos',
            'registro_afectado_id' => $projectId,
            'fecha' => now(),
        ]);
    }

    private function truthy(mixed $value): bool
    {
        return is_bool($value)
            ? $value
            : in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }
}
