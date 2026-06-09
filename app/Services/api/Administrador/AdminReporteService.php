<?php

namespace App\Services\api\Administrador;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminReporteService
{
    public function summary(array $filters = []): array
    {
        $from = ! empty($filters['desde'])
            ? Carbon::parse($filters['desde'])->startOfDay()
            : now()->subDays(29)->startOfDay();
        $to = ! empty($filters['hasta'])
            ? Carbon::parse($filters['hasta'])->endOfDay()
            : now()->endOfDay();

        $users = $this->inPeriod(DB::table('usuarios'), 'created_at', $from, $to);
        $projects = $this->inPeriod(DB::table('proyectos'), 'created_at', $from, $to);
        $participations = $this->inPeriod(DB::table('participaciones'), 'created_at', $from, $to);
        $audit = $this->inPeriod(DB::table('bitacoras'), 'fecha', $from, $to);

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'generatedAt' => now()->toIso8601String(),
            ],
            'users' => [
                'total' => (clone $users)->count(),
                'byStatus' => $this->countsBy($users, 'estado'),
                'byRole' => $this->countsBy($users, 'rol'),
            ],
            'projects' => [
                'total' => (clone $projects)->count(),
                'published' => (clone $projects)->where('estado_publicacion', 'publicado')->count(),
                'featured' => (clone $projects)->whereRaw('es_destacado IS TRUE')->count(),
                'deleted' => (clone $projects)->whereNotNull('deleted_at')->count(),
                'byPublicationStatus' => $this->countsBy($projects, 'estado_publicacion'),
                'byDevelopmentStatus' => $this->countsBy($projects, 'estado_desarrollo'),
            ],
            'participations' => [
                'total' => (clone $participations)->count(),
                'validated' => (clone $participations)->whereRaw('participacion_validada IS TRUE')->count(),
                'owners' => (clone $participations)->whereRaw('es_propietario IS TRUE')->count(),
                'byStatus' => $this->countsBy($participations, 'estado_participacion'),
            ],
            'audit' => [
                'total' => (clone $audit)->count(),
                'byAction' => $this->countsBy($audit, 'accion', 8),
                'byModule' => $this->countsBy($audit, 'tabla_afectada', 8),
            ],
        ];
    }

    private function inPeriod(Builder $query, string $column, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween($column, [$from, $to]);
    }

    private function countsBy(Builder $query, string $column, ?int $limit = null): array
    {
        $results = (clone $query)
            ->select($column)
            ->selectRaw('COUNT(*) as total')
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('total');

        if ($limit) {
            $results->limit($limit);
        }

        return $results->get()
            ->mapWithKeys(fn ($item): array => [(string) $item->{$column} => (int) $item->total])
            ->all();
    }
}
