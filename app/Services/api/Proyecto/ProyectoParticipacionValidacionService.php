<?php

namespace App\Services\api\Proyecto;

use App\Services\api\ProyectoNotificacionGuardadoService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProyectoParticipacionValidacionService
{
    public const MOTIVO_RECALCULO = 'recalculo';

    public const MOTIVO_PERDIDA_ACCESO_PROVEEDOR = 'perdida_acceso_proveedor';

    public function __construct(
        private readonly ProyectoNotificacionGuardadoService $proyectoNotificacionGuardadoService,
    ) {}

    public function reconciliarProyecto(
        int $idProyecto,
        string $motivo = self::MOTIVO_RECALCULO,
        ?array $participacionIds = null,
    ): array {
        $resultado = DB::transaction(function () use ($idProyecto, $motivo, $participacionIds) {
            $participaciones = $this->participacionesActivas($idProyecto, $participacionIds);
            $repositorios = $this->repositoriosActivos($idProyecto);

            if ($participaciones->isEmpty()) {
                return $this->resultadoVacio();
            }

            $this->eliminarRelacionesFueraDelProyecto($participaciones, $repositorios);
            $this->sincronizarRelacionesConValidaciones($participaciones, $repositorios);

            $permiteSinValidacion = $this->permiteParticipantesSinValidacion($idProyecto);
            $actualizadas = 0;
            $validadas = 0;
            $noValidadas = 0;
            $desvinculadas = [];
            $usuariosValidados = [];
            $propietariosProtegidos = 0;

            foreach ($participaciones as $participacion) {
                $antesValidada = $this->databaseBool($participacion->participacion_validada ?? false);
                $ahoraValidada = DB::table('participacion_repositorios')
                    ->where('id_participacion', $participacion->id_participacion)
                    ->whereRaw('validado = TRUE')
                    ->exists();

                if ($antesValidada !== $ahoraValidada) {
                    $actualizadas++;
                }

                if (! $antesValidada && $ahoraValidada) {
                    $usuariosValidados[] = (int) $participacion->id_usuario;
                }

                $ahoraValidada ? $validadas++ : $noValidadas++;

                $debeDesvincular = $motivo === self::MOTIVO_PERDIDA_ACCESO_PROVEEDOR
                    && $participacionIds !== null
                    && $antesValidada
                    && ! $ahoraValidada
                    && ! $permiteSinValidacion;

                if ($debeDesvincular && $this->databaseBool($participacion->es_propietario ?? false)) {
                    $debeDesvincular = false;
                    $propietariosProtegidos++;
                }

                if ($debeDesvincular) {
                    DB::table('participacion_repositorios')
                        ->where('id_participacion', $participacion->id_participacion)
                        ->delete();

                    DB::table('participaciones')
                        ->where('id_participacion', $participacion->id_participacion)
                        ->update([
                            'participacion_validada' => $this->dbBool(false),
                            'estado_participacion' => 'retirado',
                            'deleted_at' => now(),
                            'updated_at' => now(),
                        ]);

                    $desvinculadas[] = (int) $participacion->id_usuario;
                    continue;
                }

                DB::table('participaciones')
                    ->where('id_participacion', $participacion->id_participacion)
                    ->update([
                        'participacion_validada' => $this->dbBool($ahoraValidada),
                        'updated_at' => now(),
                    ]);
            }

            return [
                'participaciones_revisadas' => $participaciones->count(),
                'participaciones_actualizadas' => $actualizadas,
                'participaciones_validadas' => $validadas,
                'participaciones_no_validadas' => $noValidadas,
                'usuarios_desvinculados' => $desvinculadas,
                'usuarios_validados' => $usuariosValidados,
                'propietarios_protegidos' => $propietariosProtegidos,
            ];
        });

        foreach ($resultado['usuarios_validados'] as $idUsuario) {
            $this->proyectoNotificacionGuardadoService
                ->notificarParticipacionValidada($idProyecto, $idUsuario);
        }

        foreach ($resultado['usuarios_desvinculados'] as $idUsuario) {
            $this->proyectoNotificacionGuardadoService
                ->notificarDesvinculacionAutomaticaPorPerdidaValidacion($idProyecto, $idUsuario);
        }

        return [
            'status' => 'success',
            'motivo' => $motivo,
            ...$resultado,
        ];
    }

    private function participacionesActivas(int $idProyecto, ?array $participacionIds): Collection
    {
        $ids = collect($participacionIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        return DB::table('participaciones')
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->when($participacionIds !== null, fn ($query) => $query->whereIn('id_participacion', $ids->all()))
            ->get();
    }

    private function repositoriosActivos(int $idProyecto): Collection
    {
        return DB::table('proyecto_repositorios as pr')
            ->leftJoin('repositorio_github as rg', 'rg.id_proyecto_repositorio', '=', 'pr.id_proyecto_repositorio')
            ->where('pr.id_proyecto', $idProyecto)
            ->whereNull('pr.deleted_at')
            ->select(
                'pr.id_proyecto_repositorio',
                'rg.id_repositorio_github'
            )
            ->get();
    }

    private function eliminarRelacionesFueraDelProyecto(Collection $participaciones, Collection $repositorios): void
    {
        $query = DB::table('participacion_repositorios')
            ->whereIn('id_participacion', $participaciones->pluck('id_participacion')->all());

        $repoIds = $repositorios->pluck('id_proyecto_repositorio')->all();

        if ($repoIds === []) {
            $query->delete();
            return;
        }

        $query->whereNotIn('id_proyecto_repositorio', $repoIds)->delete();
    }

    private function sincronizarRelacionesConValidaciones(Collection $participaciones, Collection $repositorios): void
    {
        $remoteIds = $repositorios
            ->pluck('id_repositorio_github')
            ->filter()
            ->values();

        if ($remoteIds->isEmpty()) {
            return;
        }

        $validaciones = DB::table('usuario_repositorio_validaciones')
            ->whereIn('id_usuario', $participaciones->pluck('id_usuario')->all())
            ->whereIn('id_repositorio_github', $remoteIds->all())
            ->get()
            ->keyBy(fn ($row) => $row->id_usuario.':'.$row->id_repositorio_github);

        foreach ($participaciones as $participacion) {
            foreach ($repositorios as $repositorio) {
                if (! $repositorio->id_repositorio_github) {
                    continue;
                }

                $validacion = $validaciones->get(
                    $participacion->id_usuario.':'.$repositorio->id_repositorio_github
                );

                $existente = DB::table('participacion_repositorios')
                    ->where('id_participacion', $participacion->id_participacion)
                    ->where('id_proyecto_repositorio', $repositorio->id_proyecto_repositorio)
                    ->exists();

                if (! $validacion && ! $existente) {
                    continue;
                }

                $validada = $this->databaseBool($validacion->validado ?? false);

                $keys = [
                    'id_participacion' => $participacion->id_participacion,
                    'id_proyecto_repositorio' => $repositorio->id_proyecto_repositorio,
                ];
                $values = [
                    'validado' => $this->dbBool($validada),
                    'es_propietario' => $this->dbBool(
                        $this->databaseBool($validacion->es_propietario ?? false)
                    ),
                    'validado_at' => $validada ? ($validacion->validado_at ?? now()) : null,
                    'updated_at' => now(),
                ];

                if ($existente) {
                    DB::table('participacion_repositorios')->where($keys)->update($values);
                    continue;
                }

                DB::table('participacion_repositorios')->insert([
                    ...$keys,
                    ...$values,
                    'created_at' => now(),
                ]);
            }
        }
    }

    private function permiteParticipantesSinValidacion(int $idProyecto): bool
    {
        $value = DB::table('proyecto_configuraciones')
            ->where('id_proyecto', $idProyecto)
            ->value('permitir_participantes_sin_validacion');

        return $this->databaseBool($value);
    }

    private function resultadoVacio(): array
    {
        return [
            'participaciones_revisadas' => 0,
            'participaciones_actualizadas' => 0,
            'participaciones_validadas' => 0,
            'participaciones_no_validadas' => 0,
            'usuarios_desvinculados' => [],
            'usuarios_validados' => [],
            'propietarios_protegidos' => 0,
        ];
    }

    private function databaseBool(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }

    private function dbBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
