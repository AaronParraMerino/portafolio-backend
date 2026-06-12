<?php

namespace App\Services\api\Proyecto;

use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProyectoRepositorioDetectadoService
{
    public function reposForUser(int $userId, string $provider): Collection
    {
        $rows = $this->baseQuery($userId, $provider)->get();
        $deletedProjectIds = $rows
            ->filter(fn ($row) => ! is_null($row->proyecto_deleted_at ?? null))
            ->pluck('id_proyecto')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $recoveryByProject = $this->recoveryByProject($userId, $deletedProjectIds);

        return $rows
            ->map(function ($row) use ($provider, $recoveryByProject) {
                $repoAssigned = ! is_null($row->id_proyecto);
                $projectDeleted = $repoAssigned && ! is_null($row->proyecto_deleted_at);
                $validated = $this->databaseBool($row->validado ?? false);
                if (! $validated) {
                    return null;
                }
                if ($repoAssigned && ! $projectDeleted && ! is_null($row->id_participacion)) {
                    return null;
                }

                $recovery = $projectDeleted
                    ? ($recoveryByProject[(int) $row->id_proyecto] ?? $this->emptyRecovery())
                    : null;

                return [
                    'id_proyecto_repositorio' => $row->id_proyecto_repositorio,
                    'id_proyecto' => $row->id_proyecto,
                    'nombre' => $row->nombre,
                    'url_repositorio' => $row->url_repositorio,
                    'descripcion' => $row->descripcion,
                    'tipo' => $row->tipo,
                    'proveedor' => $provider,
                    'estado_vinculacion' => $projectDeleted
                        ? 'proyecto_eliminado'
                        : ($repoAssigned ? 'en_uso' : 'libre'),
                    'puede_unirse' => $repoAssigned
                        && ! $projectDeleted
                        && $validated,
                    'proyecto' => $repoAssigned ? [
                        'id_proyecto' => $row->id_proyecto,
                        'titulo' => $row->proyecto_titulo,
                        'descripcion' => $row->proyecto_descripcion,
                        'estado_publicacion' => $row->proyecto_estado_publicacion,
                        'estado_desarrollo' => $row->proyecto_estado_desarrollo,
                        'eliminado' => $projectDeleted,
                        'eliminado_at' => $row->proyecto_deleted_at,
                    ] : null,
                    'recuperacion' => $recovery,
                    'repo_github' => [
                        'id_repositorio_github' => $row->id_repositorio_github,
                        'github_repo_id' => $row->github_repo_id,
                        'owner' => $row->github_owner,
                        'repo_name' => $row->github_repo_name,
                        'is_private' => $this->databaseBool($row->is_private ?? false),
                        'is_archived' => $this->databaseBool($row->is_archived ?? false),
                        'last_push_at' => $row->last_push_at,
                        'stars_count' => $row->stars_count,
                    ],
                    'validacion' => [
                        'validado' => $validated,
                        'relacion_github' => $row->relacion_github ?? 'unknown',
                        'es_propietario' => $this->databaseBool($row->es_propietario ?? false),
                        'ultima_verificacion_at' => $row->ultima_verificacion_at ?? null,
                    ],
                ];
            })
            ->filter()
            ->values();
    }

    public function deletedProjectGroups(Collection $repos): array
    {
        return $repos
            ->filter(fn (array $repo) => ($repo['estado_vinculacion'] ?? null) === 'proyecto_eliminado')
            ->groupBy('id_proyecto')
            ->map(function (Collection $projectRepos) {
                $first = $projectRepos->first();

                return [
                    'proyecto' => $first['proyecto'],
                    'recuperacion' => $first['recuperacion'],
                    'repositorios_detectados' => $projectRepos
                        ->map(fn (array $repo) => [
                            'id_proyecto_repositorio' => $repo['id_proyecto_repositorio'],
                            'nombre' => $repo['nombre'],
                            'proveedor' => $repo['proveedor'],
                            'url_repositorio' => $repo['url_repositorio'],
                            'validacion' => $repo['validacion'],
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function baseQuery(int $userId, string $provider)
    {
        return DB::table('proyecto_repositorios as pr')
            ->join('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->leftJoin('usuario_repositorio_validaciones as urv', function ($join) use ($userId) {
                $join->on('urv.id_repositorio_github', '=', 'rg.id_repositorio_github')
                    ->where('urv.id_usuario', '=', $userId);
            })
            ->leftJoin('proyectos as p', 'p.id_proyecto', '=', 'pr.id_proyecto')
            ->leftJoin('participaciones as pa', function ($join) use ($userId) {
                $join->on('pa.id_proyecto', '=', 'pr.id_proyecto')
                    ->where('pa.id_usuario', '=', $userId)
                    ->whereNull('pa.deleted_at');
            })
            ->leftJoin('proyecto_configuraciones as pc', 'pc.id_proyecto', '=', 'pr.id_proyecto')
            ->where('pr.proveedor', $provider)
            ->whereNull('pr.deleted_at')
            ->whereRaw('urv.validado = TRUE')
            ->select([
                'pr.id_proyecto_repositorio',
                'pr.id_proyecto',
                'pr.nombre',
                'pr.url_repositorio',
                'pr.descripcion',
                'pr.tipo',
                'rg.id_repositorio_github',
                'rg.github_repo_id',
                'rg.github_owner',
                'rg.github_repo_name',
                'rg.is_private',
                'rg.is_archived',
                'rg.last_push_at',
                'rg.stars_count',
                'urv.validado',
                'urv.relacion_github',
                'urv.es_propietario',
                'urv.ultima_verificacion_at',
                'pc.permitir_participantes_sin_validacion',
                'pa.id_participacion',
                'p.titulo as proyecto_titulo',
                'p.descripcion as proyecto_descripcion',
                'p.estado_publicacion as proyecto_estado_publicacion',
                'p.estado_desarrollo as proyecto_estado_desarrollo',
                'p.deleted_at as proyecto_deleted_at',
            ])
            ->orderByDesc('pr.updated_at');
    }

    private function recoveryByProject(int $userId, Collection $projectIds): array
    {
        if ($projectIds->isEmpty()) {
            return [];
        }

        $validations = DB::table('proyecto_repositorios as pr')
            ->join('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->join('usuario_repositorio_validaciones as urv', function ($join) use ($userId) {
                $join->on('urv.id_repositorio_github', '=', 'rg.id_repositorio_github')
                    ->where('urv.id_usuario', '=', $userId)
                    ->whereRaw('urv.validado = TRUE');
            })
            ->whereIn('pr.id_proyecto', $projectIds)
            ->whereNull('pr.deleted_at')
            ->get([
                'pr.id_proyecto',
                'urv.relacion_github',
                'urv.es_propietario',
            ])
            ->groupBy('id_proyecto');

        $activeParticipations = DB::table('participaciones')
            ->where('id_usuario', $userId)
            ->whereIn('id_proyecto', $projectIds)
            ->whereNull('deleted_at')
            ->pluck('id_proyecto')
            ->mapWithKeys(fn ($id) => [(int) $id => true]);

        $repositoryCounts = DB::table('proyecto_repositorios')
            ->whereIn('id_proyecto', $projectIds)
            ->whereNull('deleted_at')
            ->selectRaw('id_proyecto, COUNT(*) as total')
            ->groupBy('id_proyecto')
            ->pluck('total', 'id_proyecto');

        $ownerCounts = DB::table('proyecto_repositorios as pr')
            ->join('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->join('usuario_repositorio_validaciones as urv', 'urv.id_repositorio_github', '=', 'rg.id_repositorio_github')
            ->whereIn('pr.id_proyecto', $projectIds)
            ->whereNull('pr.deleted_at')
            ->whereRaw('urv.validado = TRUE')
            ->where(function ($query) {
                $query->whereRaw('urv.es_propietario = TRUE')
                    ->orWhere('urv.relacion_github', 'owner');
            })
            ->selectRaw('pr.id_proyecto, COUNT(DISTINCT urv.id_usuario) as total')
            ->groupBy('pr.id_proyecto')
            ->pluck('total', 'pr.id_proyecto');

        $latestRequests = DB::table('notificaciones')
            ->where('id_usuario_actor', $userId)
            ->where('tipo', 'project_restore_request')
            ->whereIn('contexto_referencia', $projectIds->map(fn ($id) => 'proyecto_'.$id))
            ->orderByDesc('created_at')
            ->get()
            ->unique('contexto_referencia')
            ->keyBy('contexto_referencia');

        return $projectIds->mapWithKeys(function ($projectId) use (
            $validations,
            $activeParticipations,
            $latestRequests,
            $repositoryCounts,
            $ownerCounts
        ) {
            $rows = $validations->get($projectId, collect());
            $isOwner = $rows->contains(fn ($row) => $this->databaseBool($row->es_propietario ?? false)
                || strtolower((string) ($row->relacion_github ?? '')) === 'owner');
            $validated = $rows->isNotEmpty();
            $request = $latestRequests->get('proyecto_'.$projectId);
            $requestPending = ($request->accion_estado ?? null) === 'pendiente';
            $availableAt = $request->accion_disponible_nuevamente_at ?? null;
            $cooldownActive = $availableAt && now()->lt(Carbon::parse($availableAt));
            $ownerCount = (int) ($ownerCounts[$projectId] ?? 0);
            $canRestore = $isOwner || ($validated && $ownerCount === 0);

            return [(int) $projectId => [
                'puede_restaurar' => $canRestore,
                'puede_solicitar_restauracion' => $validated && ! $canRestore && ! $requestPending && ! $cooldownActive,
                'puede_liberar_repositorio' => $isOwner,
                'requiere_unirse_para_solicitar' => $validated
                    && ! $isOwner
                    && ! ($activeParticipations[(int) $projectId] ?? false),
                'solicitud_pendiente' => $requestPending,
                'puede_solicitar_nuevamente_at' => $availableAt,
                'relacion_validada' => $validated,
                'es_propietario_repositorio' => $isOwner,
                'repositorios_vinculados_total' => (int) ($repositoryCounts[$projectId] ?? 0),
                'propietarios_validados_total' => $ownerCount,
            ]];
        })->all();
    }

    private function emptyRecovery(): array
    {
        return [
            'puede_restaurar' => false,
            'puede_solicitar_restauracion' => false,
            'puede_liberar_repositorio' => false,
            'requiere_unirse_para_solicitar' => false,
            'solicitud_pendiente' => false,
            'puede_solicitar_nuevamente_at' => null,
            'relacion_validada' => false,
            'es_propietario_repositorio' => false,
            'repositorios_vinculados_total' => 0,
            'propietarios_validados_total' => 0,
        ];
    }

    private function databaseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }
}
