<?php

namespace App\Services\api\Proyecto;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProyectoProveedorDesvinculacionService
{
    public function __construct(
        private readonly ProyectoParticipacionValidacionService $proyectoParticipacionValidacionService,
    ) {}

    public function desvincularProveedor(int $idUsuario, string $proveedor): array
    {
        if (! in_array($proveedor, ['github', 'gitlab'], true)) {
            return [
                'status' => 'ignored',
                'proveedor' => $proveedor,
                'validaciones_invalidadas' => 0,
                'proyectos_reconciliados' => 0,
                'usuarios_desvinculados' => [],
            ];
        }

        $validaciones = $this->validacionesActivasDelProveedor($idUsuario, $proveedor)->get();

        if ($validaciones->isEmpty()) {
            return $this->resultadoVacio($proveedor);
        }

        return $this->invalidarValidaciones(
            $idUsuario,
            $proveedor,
            $validaciones,
            'Validacion revocada por desvinculacion explicita de '.$proveedor.'.'
        );
    }

    public function invalidarRepositoriosAusentesConfirmados(
        int $idUsuario,
        string $proveedor,
        array $repositoriosRemotosIds,
    ): array {
        if (! in_array($proveedor, ['github', 'gitlab'], true)) {
            return $this->resultadoIgnorado($proveedor);
        }

        $ids = collect($repositoriosRemotosIds)
            ->map(fn ($id) => (string) $id)
            ->filter(fn ($id) => $id !== '')
            ->unique()
            ->values();

        $query = $this->validacionesActivasDelProveedor($idUsuario, $proveedor)
            ->whereNotNull('rg.github_repo_id');

        if ($ids->isNotEmpty()) {
            $query->whereNotIn('rg.github_repo_id', $ids->all());
        }

        $validaciones = $query->get();

        if ($validaciones->isEmpty()) {
            return $this->resultadoVacio($proveedor);
        }

        return $this->invalidarValidaciones(
            $idUsuario,
            $proveedor,
            $validaciones,
            'Validacion revocada porque '.$proveedor.' confirmo la perdida de acceso al repositorio.'
        );
    }

    public function reconciliarRepositoriosPresentesConfirmados(
        int $idUsuario,
        string $proveedor,
        array $repositoriosRemotosIds,
    ): array {
        if (! in_array($proveedor, ['github', 'gitlab'], true)) {
            return [
                'status' => 'ignored',
                'proveedor' => $proveedor,
                'proyectos_reconciliados' => 0,
                'usuarios_validados' => [],
            ];
        }

        $ids = collect($repositoriosRemotosIds)
            ->map(fn ($id) => (string) $id)
            ->filter(fn ($id) => $id !== '')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [
                'status' => 'success',
                'proveedor' => $proveedor,
                'proyectos_reconciliados' => 0,
                'usuarios_validados' => [],
            ];
        }

        $proyectos = $this->validacionesActivasDelProveedor($idUsuario, $proveedor)
            ->whereIn('rg.github_repo_id', $ids->all())
            ->whereNotNull('pr.id_proyecto')
            ->pluck('pr.id_proyecto')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $usuariosValidados = [];
        $participacionesValidadas = 0;
        foreach ($proyectos as $idProyecto) {
            $participacionId = DB::table('participaciones')
                ->where('id_usuario', $idUsuario)
                ->where('id_proyecto', $idProyecto)
                ->whereNull('deleted_at')
                ->value('id_participacion');

            if (! $participacionId) {
                continue;
            }

            $resultado = $this->proyectoParticipacionValidacionService->reconciliarProyecto(
                $idProyecto,
                ProyectoParticipacionValidacionService::MOTIVO_RECALCULO,
                [(int) $participacionId],
            );

            $usuariosValidados = array_merge(
                $usuariosValidados,
                $resultado['usuarios_validados'] ?? []
            );
            $participacionesValidadas += count($resultado['usuarios_validados'] ?? []);
        }

        return [
            'status' => 'success',
            'proveedor' => $proveedor,
            'proyectos_reconciliados' => $proyectos->count(),
            'participaciones_validadas' => $participacionesValidadas,
            'usuarios_validados' => array_values(array_unique($usuariosValidados)),
        ];
    }

    private function invalidarValidaciones(
        int $idUsuario,
        string $proveedor,
        Collection $validaciones,
        string $detalle,
    ): array {
        DB::table('usuario_repositorio_validaciones')
            ->whereIn(
                'id_usuario_repositorio_validacion',
                $validaciones->pluck('id_usuario_repositorio_validacion')->all()
            )
            ->update([
                'relacion_github' => 'unknown',
                'es_propietario' => $this->dbBool(false),
                'validado' => $this->dbBool(false),
                'validado_at' => null,
                'ultima_verificacion_at' => now(),
                'permisos_github' => null,
                'detalle_validacion' => $detalle,
                'updated_at' => now(),
            ]);

        $proyectos = $validaciones
            ->pluck('id_proyecto')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $usuariosDesvinculados = [];
        foreach ($proyectos as $idProyecto) {
            $participacionId = DB::table('participaciones')
                ->where('id_usuario', $idUsuario)
                ->where('id_proyecto', $idProyecto)
                ->whereNull('deleted_at')
                ->value('id_participacion');

            if (! $participacionId) {
                continue;
            }

            $resultado = $this->proyectoParticipacionValidacionService->reconciliarProyecto(
                $idProyecto,
                ProyectoParticipacionValidacionService::MOTIVO_PERDIDA_ACCESO_PROVEEDOR,
                [(int) $participacionId],
            );

            $usuariosDesvinculados = array_merge(
                $usuariosDesvinculados,
                $resultado['usuarios_desvinculados'] ?? []
            );
        }

        return [
            'status' => 'success',
            'proveedor' => $proveedor,
            'validaciones_invalidadas' => $validaciones->count(),
            'proyectos_reconciliados' => $proyectos->count(),
            'usuarios_desvinculados' => array_values(array_unique($usuariosDesvinculados)),
        ];
    }

    private function validacionesActivasDelProveedor(int $idUsuario, string $proveedor)
    {
        return DB::table('usuario_repositorio_validaciones as urv')
            ->join('repositorio_github as rg', 'rg.id_repositorio_github', '=', 'urv.id_repositorio_github')
            ->join('proyecto_repositorios as pr', 'pr.id_proyecto_repositorio', '=', 'rg.id_proyecto_repositorio')
            ->where('urv.id_usuario', $idUsuario)
            ->where('pr.proveedor', $proveedor)
            ->whereRaw('urv.validado = TRUE')
            ->select(
                'urv.id_usuario_repositorio_validacion',
                'pr.id_proyecto',
                'rg.github_repo_id'
            );
    }

    private function resultadoVacio(string $proveedor): array
    {
        return [
            'status' => 'success',
            'proveedor' => $proveedor,
            'validaciones_invalidadas' => 0,
            'proyectos_reconciliados' => 0,
            'usuarios_desvinculados' => [],
        ];
    }

    private function resultadoIgnorado(string $proveedor): array
    {
        return [
            ...$this->resultadoVacio($proveedor),
            'status' => 'ignored',
        ];
    }

    private function dbBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
