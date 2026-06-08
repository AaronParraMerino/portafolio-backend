<?php

namespace App\Services\api\Proyecto;

use Illuminate\Support\Facades\DB;

class ProyectoConsultaService
{
    public function __construct(private readonly ProyectoSerializer $proyectoSerializer) {}

    public function listByUser(int $userId): array
    {
        $projects = DB::table('participaciones as p')
            ->join('proyectos as pr', 'pr.id_proyecto', '=', 'p.id_proyecto')
            ->where('p.id_usuario', $userId)
            ->whereNull('p.deleted_at')
            ->whereNull('pr.deleted_at')
            ->orderByDesc('pr.updated_at')
            ->select(
                'pr.*',
                'p.id_participacion',
                'p.id_usuario as participacion_id_usuario',
                'p.rol',
                'p.descripcion_aporte',
                'p.es_propietario',
                'p.participacion_validada',
                'p.visibilidad',
                'p.fecha_inicio as part_fecha_inicio',
                'p.fecha_fin as part_fecha_fin'
            )
            ->get();

        $context = $this->proyectoSerializer->loadIndexContext($projects, $userId);

        return $projects
            ->map(fn ($project) => $this->proyectoSerializer->serialize((array) $project, $context))
            ->values()
            ->all();
    }

    public function findForUser(int $userId, int $idProyecto): ?array
    {
        if ($userId <= 0 || $idProyecto <= 0) {
            return null;
        }

        $row = DB::table('participaciones as p')
            ->join('proyectos as pr', 'pr.id_proyecto', '=', 'p.id_proyecto')
            ->where('p.id_usuario', $userId)
            ->where('pr.id_proyecto', $idProyecto)
            ->whereNull('p.deleted_at')
            ->whereNull('pr.deleted_at')
            ->select(
                'pr.*',
                'p.id_participacion',
                'p.id_usuario as participacion_id_usuario',
                'p.rol',
                'p.descripcion_aporte',
                'p.es_propietario',
                'p.participacion_validada',
                'p.visibilidad',
                'p.fecha_inicio as part_fecha_inicio',
                'p.fecha_fin as part_fecha_fin'
            )
            ->first();

        return $row ? (array) $row : null;
    }

    public function findSerializedForUser(int $userId, int $idProyecto): ?array
    {
        $project = $this->findForUser($userId, $idProyecto);

        return $project ? $this->proyectoSerializer->serialize($project) : null;
    }
}
