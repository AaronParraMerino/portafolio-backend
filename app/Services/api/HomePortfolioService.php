<?php

namespace App\Services\api;

use App\Models\HabilidadUsuario;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HomePortfolioService
{
    private const DEFAULT_LIMIT = 6;
    private const SKILLS_LIMIT = 3;

    public function getFeaturedPortfolios(int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, 12));

        return [
            'ultimas_actualizaciones' => $this->recentlyUpdated($limit),
            'mas_proyectos' => [],
            'mas_experiencia' => $this->rankedBy('total_experiencias', $limit),
            'mas_habilidades' => $this->rankedBy('total_habilidades', $limit),
            'meta' => [
                'proyectos_disponibles' => false,
                'mensaje_proyectos' => 'El modelo de proyectos no existe en el backend actual.',
            ],
        ];
    }

    public function getPublicPortfolio(int $userId): ?array
    {
        $user = $this->basePortfolioQuery()
            ->where('usuarios.id_usuario', $userId)
            ->first();

        if (! $user) {
            return null;
        }

        return $this->mapPortfolios(new Collection([$user]))[0] ?? null;
    }

    private function recentlyUpdated(int $limit): array
    {
        $users = $this->basePortfolioQuery()
            ->orderByDesc('ultima_actividad')
            ->orderBy('usuarios.nombre')
            ->limit($limit)
            ->get();

        return $this->mapPortfolios($users);
    }

    private function rankedBy(string $field, int $limit): array
    {
        $query = $this->basePortfolioQuery();

        if ($field === 'total_experiencias') {
            $query->whereRaw('COALESCE(experiencias_publicas.total_experiencias, 0) > 0');
        }

        if ($field === 'total_habilidades') {
            $query->whereRaw('COALESCE(habilidades_publicas.total_habilidades, 0) > 0');
        }

        $users = $query
            ->orderByDesc($field)
            ->orderByDesc('ultima_actividad')
            ->orderBy('usuarios.nombre')
            ->limit($limit)
            ->get();

        return $this->mapPortfolios($users);
    }

    private function basePortfolioQuery()
    {
        $experienceTotals = DB::table('experiencias')
            ->select('usuario_id')
            ->selectRaw('COUNT(*) AS total_experiencias')
            ->selectRaw('MAX(fecha_modificacion) AS experiencias_actualizadas')
            ->whereRaw('es_publico = true')
            ->where('tipo', 'laboral')
            ->groupBy('usuario_id');

        $skillTotals = DB::table('habilidades_usuario')
            ->join('habilidades', 'habilidades.id_habilidad', '=', 'habilidades_usuario.habilidad_id')
            ->select('habilidades_usuario.usuario_id')
            ->selectRaw('COUNT(*) AS total_habilidades')
            ->selectRaw('MAX(habilidades_usuario.fecha_modificacion) AS habilidades_actualizadas')
            ->whereRaw('habilidades_usuario.es_visible = true')
            ->whereRaw('habilidades.estado = true')
            ->groupBy('habilidades_usuario.usuario_id');

        return Usuario::query()
            ->join('perfiles', 'perfiles.usuario_id', '=', 'usuarios.id_usuario')
            ->leftJoin('visibilidad_campos as vis_profesion', function ($join) {
                $join->on('vis_profesion.usuario_id', '=', 'usuarios.id_usuario')
                    ->whereRaw("vis_profesion.campo = 'profesion'");
            })
            ->leftJoin('visibilidad_campos as vis_biografia', function ($join) {
                $join->on('vis_biografia.usuario_id', '=', 'usuarios.id_usuario')
                    ->whereRaw("vis_biografia.campo = 'biografia'");
            })
            ->leftJoin('visibilidad_campos as vis_ciudad', function ($join) {
                $join->on('vis_ciudad.usuario_id', '=', 'usuarios.id_usuario')
                    ->whereRaw("vis_ciudad.campo = 'ciudad'");
            })
            ->leftJoin('visibilidad_campos as vis_pais', function ($join) {
                $join->on('vis_pais.usuario_id', '=', 'usuarios.id_usuario')
                    ->whereRaw("vis_pais.campo = 'pais'");
            })
            ->leftJoinSub($experienceTotals, 'experiencias_publicas', function ($join) {
                $join->on('experiencias_publicas.usuario_id', '=', 'usuarios.id_usuario');
            })
            ->leftJoinSub($skillTotals, 'habilidades_publicas', function ($join) {
                $join->on('habilidades_publicas.usuario_id', '=', 'usuarios.id_usuario');
            })
            ->where('usuarios.estado', 'activo')
            ->whereRaw('perfiles.es_publico = true')
            ->select([
                'usuarios.id_usuario',
                'usuarios.nombre',
                'usuarios.apellido',
                'perfiles.foto_perfil',
                'usuarios.updated_at as usuario_actualizado',
                'perfiles.updated_at as perfil_actualizado',
            ])
            ->selectRaw('CASE WHEN COALESCE(vis_profesion.visible, false) = true THEN perfiles.profesion ELSE NULL END AS profesion')
            ->selectRaw('CASE WHEN COALESCE(vis_biografia.visible, false) = true THEN perfiles.biografia ELSE NULL END AS biografia')
            ->selectRaw('CASE WHEN COALESCE(vis_ciudad.visible, false) = true THEN perfiles.ciudad ELSE NULL END AS ciudad')
            ->selectRaw('CASE WHEN COALESCE(vis_pais.visible, false) = true THEN perfiles.pais ELSE NULL END AS pais')
            ->selectRaw('COALESCE(experiencias_publicas.total_experiencias, 0) AS total_experiencias')
            ->selectRaw('COALESCE(habilidades_publicas.total_habilidades, 0) AS total_habilidades')
            ->selectRaw('0 AS total_proyectos')
            ->selectRaw("
                GREATEST(
                    COALESCE(usuarios.updated_at, '1970-01-01'),
                    COALESCE(perfiles.updated_at, '1970-01-01'),
                    COALESCE(experiencias_publicas.experiencias_actualizadas, '1970-01-01'),
                    COALESCE(habilidades_publicas.habilidades_actualizadas, '1970-01-01')
                ) AS ultima_actividad
            ");
    }

    private function mapPortfolios(Collection $users): array
    {
        $skillsByUser = $this->featuredSkills($users->pluck('id_usuario')->all());

        return $users->map(function ($user) use ($skillsByUser) {
            $updatedAt = $this->parseDate($user->ultima_actividad)
                ?? $this->parseDate($user->perfil_actualizado)
                ?? $this->parseDate($user->usuario_actualizado);

            return [
                'id_usuario' => (int) $user->id_usuario,
                'nombre' => $user->nombre,
                'apellido' => $user->apellido,
                'nombre_completo' => trim($user->nombre . ' ' . $user->apellido),
                'foto_perfil' => $user->foto_perfil,
                'profesion' => $user->profesion,
                'resumen' => $user->biografia,
                'ciudad' => $user->ciudad,
                'pais' => $user->pais,
                'total_proyectos' => (int) $user->total_proyectos,
                'total_experiencias' => (int) $user->total_experiencias,
                'total_habilidades' => (int) $user->total_habilidades,
                'fecha_ultima_actualizacion' => $updatedAt?->toIso8601String(),
                'skills_destacadas' => $skillsByUser[$user->id_usuario] ?? [],
                'ruta_portafolio' => '/portfolio/' . $user->id_usuario,
            ];
        })->values()->all();
    }

    private function featuredSkills(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        return HabilidadUsuario::query()
            ->with('habilidad:id_habilidad,nombre,tipo')
            ->whereIn('usuario_id', $userIds)
            ->whereRaw('es_visible = true')
            ->whereHas('habilidad', function ($query) {
                $query->whereRaw('estado = true');
            })
            ->orderByDesc('fecha_modificacion')
            ->orderByDesc('id_habilidad_usuario')
            ->get()
            ->groupBy('usuario_id')
            ->map(function ($items) {
                return $items->take(self::SKILLS_LIMIT)
                    ->map(fn ($item) => $item->habilidad?->nombre)
                    ->filter()
                    ->values()
                    ->all();
            })
            ->all();
    }

    private function parseDate($value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
