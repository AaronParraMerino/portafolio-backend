<?php

namespace App\Services\api\Proyecto;

use Illuminate\Support\Facades\DB;

class ProyectoConsultaService
{
    public function __construct(private readonly ProyectoSerializer $proyectoSerializer) {}

    public function listByUser(int $userId, string $lang = 'es'): array
    {
        $projects = $this->projectsByUserQuery($userId)->get();

        return $this->serializeProjects($projects, $userId, $lang);
    }

    public function listByUserPaginated(int $userId, int $page, int $perPage, string $lang = 'es'): array
    {
        $paginator = $this->projectsByUserQuery($userId)
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $this->serializeProjects(collect($paginator->items()), $userId, $lang),
            'meta' => [
                'pagina_actual' => $paginator->currentPage(),
                'ultima_pagina' => $paginator->lastPage(),
                'por_pagina' => $paginator->perPage(),
                'total' => $paginator->total(),
                'hay_mas' => $paginator->hasMorePages(),
            ],
        ];
    }

    private function projectsByUserQuery(int $userId)
    {
        return DB::table('participaciones as p')
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
            );
    }

    private function serializeProjects($projects, int $userId, string $lang): array
    {
        $context = $this->proyectoSerializer->loadIndexContext($projects, $userId);

        return $projects
            ->map(fn ($project) => $this->proyectoSerializer->serialize(
                (array) $project,
                $context,
                $lang
            ))
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

    public function findSerializedForUser(
        int $userId,
        int $idProyecto,
        string $lang = 'es'
    ): ?array
    {
        $project = $this->findForUser($userId, $idProyecto);

        return $project ? $this->proyectoSerializer->serialize($project, null, $lang) : null;
    }
}
