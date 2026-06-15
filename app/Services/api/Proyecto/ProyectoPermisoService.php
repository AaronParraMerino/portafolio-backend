<?php

namespace App\Services\api\Proyecto;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProyectoPermisoService
{
    public function userHasAccess(int $userId, int $idProyecto): bool
    {
        if ($userId <= 0 || $idProyecto <= 0) {
            return false;
        }

        return DB::table('participaciones as p')
            ->join('proyectos as pr', 'pr.id_proyecto', '=', 'p.id_proyecto')
            ->where('p.id_usuario', $userId)
            ->where('pr.id_proyecto', $idProyecto)
            ->whereNull('p.deleted_at')
            ->whereNull('pr.deleted_at')
            ->exists();
    }

    public function defaultConfiguration(): array
    {
        return [
            'permitir_participantes_sin_validacion' => false,
            'puede_editar_proyecto' => 'participantes_validados',
            'puede_administrar_proyecto' => 'propietarios',
            'github_nivel_autoridad' => 'maintainer',
            'github_prevalece_sobre_creador' => true,
            'visibilidad_usuario_sin_validacion' => 'visible',
            'permitir_remover_participantes_sin_validacion' => false,
        ];
    }

    public function configuration(int $idProyecto): array
    {
        $row = DB::table('proyecto_configuraciones')
            ->where('id_proyecto', $idProyecto)
            ->first();

        if (! $row) {
            $now = now();
            DB::table('proyecto_configuraciones')->insertOrIgnore([
                'id_proyecto' => $idProyecto,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = DB::table('proyecto_configuraciones')
                ->where('id_proyecto', $idProyecto)
                ->first();
        }

        return $this->normalizeConfiguration((array) $row, $idProyecto);
    }

    public function normalizeConfiguration(array $row, int $idProyecto): array
    {
        $config = [
            ...$this->defaultConfiguration(),
            ...$row,
        ];

        foreach ([
            'permitir_participantes_sin_validacion',
            'github_prevalece_sobre_creador',
            'permitir_remover_participantes_sin_validacion',
        ] as $key) {
            $config[$key] = $this->truthy($config[$key] ?? false);
        }

        $config['id_proyecto'] = (int) ($config['id_proyecto'] ?? $idProyecto);

        return $config;
    }

    public function defaultPermissions(): array
    {
        return [
            'puede_editar' => false,
            'puede_eliminar' => false,
            'puede_configurar' => false,
            'puede_administrar' => false,
            'puede_desvincular_participacion' => false,
            'puede_remover_participantes_sin_validacion' => false,
            'es_propietario' => false,
            'es_autoridad_github' => false,
            'participacion_validada' => false,
            'nivel_github' => null,
            'relacion_github' => null,
        ];
    }

    public function resolve(int $userId, int $idProyecto): array
    {
        $participacion = DB::table('participaciones')
            ->where('id_usuario', $userId)
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->first();

        if (! $participacion) {
            return $this->defaultPermissions();
        }

        $config = $this->configuration($idProyecto);
        $authority = $this->getGithubAuthorityForProject($userId, $idProyecto, $config);

        $isOwner = $this->truthy($participacion->es_propietario ?? false);
        $isGithubAuthority = (bool) ($authority['tiene_autoridad'] ?? false);
        $isValidated = (bool) ($participacion->participacion_validada ?? false)
            || $this->hasValidatedGithubParticipation($userId, $idProyecto);
        $adminPolicy = $config['puede_administrar_proyecto'] ?? 'propietarios';
        $canAdmin = $isOwner || ($adminPolicy === 'autoridad_github' && $isGithubAuthority);

        $canEdit = match ($config['puede_editar_proyecto'] ?? 'participantes_validados') {
            'propietarios' => $isOwner,
            'autoridad_github' => $isOwner || $isGithubAuthority,
            'participantes' => true,
            default => $isOwner || $isValidated,
        };

        return [
            'puede_editar' => $canEdit,
            'puede_eliminar' => $isOwner,
            'puede_configurar' => $canAdmin,
            'puede_administrar' => $canAdmin,
            'puede_desvincular_participacion' => $this->activeParticipantsCount($idProyecto) > 1
                && ! $this->isSoleActiveOwner($participacion, $idProyecto),
            'puede_remover_participantes_sin_validacion' => (bool) ($config['permitir_remover_participantes_sin_validacion'] ?? false) && $canAdmin,
            'es_propietario' => $isOwner,
            'es_autoridad_github' => $isGithubAuthority,
            'participacion_validada' => $isValidated,
            'nivel_github' => $authority['nivel'] ?? null,
            'relacion_github' => $authority['relacion'] ?? null,
        ];
    }

    public function resolveFromLoadedData(
        array $participacion,
        array $config,
        $validaciones,
        int $activeParticipants,
        int $activeOwners
    ): array {
        $authority = $this->getGithubAuthorityFromValidations($validaciones, $config);
        $isOwner = $this->truthy($participacion['es_propietario'] ?? false);
        $isGithubAuthority = (bool) ($authority['tiene_autoridad'] ?? false);
        $isValidated = $this->truthy($participacion['participacion_validada'] ?? false)
            || $validaciones->isNotEmpty();
        $adminPolicy = $config['puede_administrar_proyecto'] ?? 'propietarios';
        $canAdmin = $isOwner || ($adminPolicy === 'autoridad_github' && $isGithubAuthority);

        $canEdit = match ($config['puede_editar_proyecto'] ?? 'participantes_validados') {
            'propietarios' => $isOwner,
            'autoridad_github' => $isOwner || $isGithubAuthority,
            'participantes' => true,
            default => $isOwner || $isValidated,
        };

        return [
            'puede_editar' => $canEdit,
            'puede_eliminar' => $isOwner,
            'puede_configurar' => $canAdmin,
            'puede_administrar' => $canAdmin,
            'puede_desvincular_participacion' => $activeParticipants > 1 && ! ($isOwner && $activeOwners <= 1),
            'puede_remover_participantes_sin_validacion' => (bool) ($config['permitir_remover_participantes_sin_validacion'] ?? false) && $canAdmin,
            'es_propietario' => $isOwner,
            'es_autoridad_github' => $isGithubAuthority,
            'participacion_validada' => $isValidated,
            'nivel_github' => $authority['nivel'] ?? null,
            'relacion_github' => $authority['relacion'] ?? null,
        ];
    }

    public function activeParticipantsCount(int $idProyecto): int
    {
        return DB::table('participaciones')
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->count();
    }

    public function isSoleActiveOwner(object $participacion, int $idProyecto): bool
    {
        if (! $this->truthy($participacion->es_propietario ?? false)) {
            return false;
        }

        return DB::table('participaciones')
            ->where('id_proyecto', $idProyecto)
            ->whereRaw('es_propietario = TRUE')
            ->whereNull('deleted_at')
            ->count() <= 1;
    }

    public function hasValidatedGithubParticipation(int $userId, int $idProyecto): bool
    {
        $repoIds = $this->projectRepositoryIds($idProyecto);

        if ($repoIds === []) {
            return false;
        }

        return DB::table('usuario_repositorio_validaciones')
            ->where('id_usuario', $userId)
            ->whereIn('id_repositorio_github', $repoIds)
            ->whereRaw('validado = TRUE')
            ->exists();
    }

    public function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function postgresBool(mixed $value): string
    {
        return $this->truthy($value) ? 'TRUE' : 'FALSE';
    }

    private function getGithubAuthorityForProject(int $userId, int $idProyecto, array $config): array
    {
        $repoIds = $this->projectRepositoryIds($idProyecto);

        if ($repoIds === []) {
            return ['tiene_autoridad' => false, 'nivel' => null, 'relacion' => null];
        }

        $validaciones = DB::table('usuario_repositorio_validaciones')
            ->where('id_usuario', $userId)
            ->whereIn('id_repositorio_github', $repoIds)
            ->whereRaw('validado = TRUE')
            ->get();

        return $this->getGithubAuthorityFromValidations($validaciones, $config);
    }

    private function getGithubAuthorityFromValidations($validaciones, array $config): array
    {
        foreach ($validaciones as $validacion) {
            $relation = Str::lower((string) ($validacion->relacion_github ?? ''));
            $permissions = $this->decodeGithubPermissions($validacion->permisos_github ?? null);
            $isOwner = (bool) ($validacion->es_propietario ?? false) || $relation === 'owner';
            $hasMaintainer = $isOwner
                || in_array($relation, ['maintainer', 'admin'], true)
                || (bool) ($permissions['admin'] ?? false);
            $hasAdminPush = $hasMaintainer || (bool) ($permissions['push'] ?? false);

            $allowed = match ($config['github_nivel_autoridad'] ?? 'maintainer') {
                'owner' => $isOwner,
                'admin_push' => $hasAdminPush,
                default => $hasMaintainer,
            };

            if ($allowed) {
                return [
                    'tiene_autoridad' => true,
                    'nivel' => $isOwner ? 'owner' : ($hasMaintainer ? 'maintainer' : 'admin_push'),
                    'relacion' => $relation ?: null,
                ];
            }
        }

        return ['tiene_autoridad' => false, 'nivel' => null, 'relacion' => null];
    }

    private function projectRepositoryIds(int $idProyecto): array
    {
        return DB::table('proyecto_repositorios as pr')
            ->join('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->where('pr.id_proyecto', $idProyecto)
            ->whereIn('pr.proveedor', ['github', 'gitlab'])
            ->whereNull('pr.deleted_at')
            ->pluck('rg.id_repositorio_github')
            ->filter()
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();
    }

    private function decodeGithubPermissions(mixed $permissions): array
    {
        if (is_array($permissions)) {
            return $permissions;
        }

        if (is_string($permissions) && trim($permissions) !== '') {
            $decoded = json_decode($permissions, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
