<?php

namespace App\Services\api\Proyecto;

use App\Services\api\ProfileImageVariantService;
use App\Services\api\ProyectoNotificacionGuardadoService;
use Illuminate\Support\Facades\DB;

class ProyectoParticipanteService
{
    public function __construct(
        private readonly ProyectoPermisoService $proyectoPermisoService,
        private readonly ProfileImageVariantService $profileImageVariants,
        private readonly ProyectoNotificacionGuardadoService $proyectoNotificacionGuardadoService,
    ) {}

    public function list(int $idProyecto, int $requestUserId): array
    {
        $config = $this->proyectoPermisoService->configuration($idProyecto);
        $permissions = $this->proyectoPermisoService->resolve($requestUserId, $idProyecto);
        $hideUnvalidated = ($config['visibilidad_usuario_sin_validacion'] ?? 'visible') === 'oculto';
        $canManageUnvalidated = (bool) ($permissions['puede_remover_participantes_sin_validacion'] ?? false);

        $repoGithubIds = DB::table('proyecto_repositorios as pr')
            ->leftJoin('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->where('pr.id_proyecto', $idProyecto)
            ->whereIn('pr.proveedor', ['github', 'gitlab'])
            ->whereNull('pr.deleted_at')
            ->pluck('rg.id_repositorio_github')
            ->filter()
            ->values();

        $systemRows = DB::table('participaciones as p')
            ->join('usuarios as u', 'u.id_usuario', '=', 'p.id_usuario')
            ->leftJoin('perfiles as pe', 'pe.usuario_id', '=', 'u.id_usuario')
            ->leftJoin('cuentas_oauth as co', function ($join) {
                $join->on('co.usuario_id', '=', 'u.id_usuario')
                    ->where('co.provider', '=', 'github');
            })
            ->where('p.id_proyecto', $idProyecto)
            ->whereNull('p.deleted_at')
            ->select(
                'p.id_participacion',
                'p.id_usuario',
                'p.rol',
                'p.descripcion_aporte',
                'p.es_propietario',
                'p.participacion_validada',
                'u.nombre',
                'u.apellido',
                'u.correo',
                'pe.foto_perfil',
                'co.id_cuenta_oauth',
                'co.provider_user_id',
                'co.nombre as github_nombre',
                'co.foto_url as github_foto_url'
            )
            ->get();

        $userIds = $systemRows->pluck('id_usuario')->filter()->values();
        $validaciones = $repoGithubIds->isEmpty() || $userIds->isEmpty()
            ? collect()
            : DB::table('usuario_repositorio_validaciones')
                ->whereIn('id_usuario', $userIds->all())
                ->whereIn('id_repositorio_github', $repoGithubIds->all())
                ->get()
                ->groupBy('id_usuario');

        $participants = [];
        foreach ($systemRows as $row) {
            $userValidaciones = $validaciones->get($row->id_usuario, collect());
            $validacion = $userValidaciones->first(
                fn ($item) => $this->proyectoPermisoService->truthy($item->validado ?? false)
            )
                ?? $userValidaciones->first();
            $validado = $this->proyectoPermisoService->truthy(
                $validacion?->validado ?? $row->participacion_validada ?? false
            );
            $tipo = $validado ? 'usuario_github_validado' : 'usuario_sin_validacion_github';

            if (! $validado && $hideUnvalidated && ! $canManageUnvalidated) {
                continue;
            }

            $participants[] = [
                'id' => 'participacion-'.$row->id_participacion,
                'id_participacion' => (int) $row->id_participacion,
                'id_usuario' => (int) $row->id_usuario,
                'nombre' => trim(($row->nombre ?? '').' '.($row->apellido ?? '')),
                'email' => $row->correo,
                'rol' => $row->rol,
                'descripcion_aporte' => $row->descripcion_aporte,
                'es_propietario' => $this->proyectoPermisoService->truthy($row->es_propietario ?? false),
                'foto_perfil' => $row->foto_perfil,
                'avatar_thumb_url' => $this->profileImageVariants->getVariantUrl($row->foto_perfil, 'thumb'),
                'github_avatar_url' => $row->github_foto_url,
                'avatar_url' => $row->foto_perfil ?: $row->github_foto_url,
                'source' => 'sistema',
                'tipo_participante' => $tipo,
                'origen_participante' => $tipo,
                'tiene_cuenta' => true,
                'tiene_vinculacion_github' => ! is_null($row->id_cuenta_oauth),
                'validacion_github' => $validado,
                'github_id' => $row->provider_user_id,
                'github_username' => $row->github_nombre,
                'validacion' => [
                    'validado' => $validado,
                    'relacion_github' => $validacion->relacion_github ?? 'unknown',
                    'es_propietario' => $this->proyectoPermisoService->truthy(
                        $validacion->es_propietario ?? false
                    ),
                    'ultima_verificacion_at' => $validacion->ultima_verificacion_at ?? null,
                ],
            ];
        }

        return collect($participants)
            ->unique(function ($item) {
                if (! empty($item['id_usuario'])) {
                    return 'usuario:'.$item['id_usuario'];
                }

                if (! empty($item['github_id'])) {
                    return 'github-id:'.$item['github_id'];
                }

                return 'github-login:'.strtolower((string) ($item['github_username'] ?? $item['id']));
            })
            ->sortBy([
                fn ($a, $b) => $this->participantTypeOrder($a) <=> $this->participantTypeOrder($b),
                fn ($a, $b) => ((bool) ($b['es_propietario'] ?? false)) <=> ((bool) ($a['es_propietario'] ?? false)),
                fn ($a, $b) => strcmp((string) ($a['nombre'] ?? ''), (string) ($b['nombre'] ?? '')),
            ])
            ->values()
            ->all();
    }

    public function detach(int $idProyecto, int $userId): array
    {
        $participacion = DB::table('participaciones')
            ->where('id_usuario', $userId)
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->first();

        if (! $participacion) {
            return ['status' => 'not_found'];
        }

        if ($this->proyectoPermisoService->activeParticipantsCount($idProyecto) <= 1) {
            return ['status' => 'only_participant'];
        }

        if ($this->proyectoPermisoService->isSoleActiveOwner($participacion, $idProyecto)) {
            return ['status' => 'sole_owner'];
        }

        if (! $this->proyectoPermisoService->resolve($userId, $idProyecto)['puede_desvincular_participacion']) {
            return ['status' => 'forbidden'];
        }

        $this->deleteParticipation((int) $participacion->id_participacion);
        $this->proyectoNotificacionGuardadoService->notificarParticipanteDesvinculado(
            idProyecto: $idProyecto,
            idUsuarioDesvinculado: $userId
        );

        return ['status' => 'success'];
    }

    public function remove(int $idProyecto, int $participacionId, int $userId): array
    {
        $permissions = $this->proyectoPermisoService->resolve($userId, $idProyecto);

        if (! ($permissions['puede_remover_participantes_sin_validacion'] ?? false)) {
            return ['status' => 'forbidden'];
        }

        $participacion = DB::table('participaciones')
            ->where('id_participacion', $participacionId)
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->first();

        if (! $participacion) {
            return ['status' => 'not_found'];
        }

        if ((int) ($participacion->id_usuario ?? 0) === $userId) {
            return ['status' => 'self'];
        }

        if ($this->proyectoPermisoService->truthy($participacion->es_propietario ?? false)) {
            return ['status' => 'owner'];
        }

        $isValidated = $this->proyectoPermisoService->truthy($participacion->participacion_validada ?? false)
            || $this->proyectoPermisoService->hasValidatedGithubParticipation(
                (int) $participacion->id_usuario,
                $idProyecto
            );

        if ($isValidated) {
            return ['status' => 'validated'];
        }

        if ($this->proyectoPermisoService->activeParticipantsCount($idProyecto) <= 1) {
            return ['status' => 'only_participant'];
        }

        $this->deleteParticipation($participacionId);
        $this->proyectoNotificacionGuardadoService->notificarParticipanteRemovido(
            idProyecto: $idProyecto,
            idUsuarioRemovido: (int) $participacion->id_usuario,
            idUsuarioActor: $userId
        );

        return ['status' => 'success'];
    }

    private function deleteParticipation(int $participacionId): void
    {
        DB::transaction(function () use ($participacionId) {
            DB::table('participacion_repositorios')
                ->where('id_participacion', $participacionId)
                ->delete();

            DB::table('participaciones')
                ->where('id_participacion', $participacionId)
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        });
    }

    private function participantTypeOrder(array $participant): int
    {
        return match ($participant['tipo_participante'] ?? '') {
            'usuario_github_validado' => 0,
            'usuario_sin_validacion_github' => 1,
            default => 2,
        };
    }
}
