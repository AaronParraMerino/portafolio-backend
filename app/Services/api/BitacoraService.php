<?php

namespace App\Services\api;

use App\Models\Bitacora;
use App\Models\Usuario;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class BitacoraService
{
    private const DEFAULT_PAGE_SIZE = 15;
    private const MAX_PAGE_SIZE = 100;

    public function search(array $filters = []): array
    {
        $pageSize = min(
            max((int) ($filters['per_page'] ?? self::DEFAULT_PAGE_SIZE), 1),
            self::MAX_PAGE_SIZE
        );

        $baseQuery = $this->buildQuery($filters);
        $metrics = $this->metrics($baseQuery);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $baseQuery
            ->orderByDesc('fecha')
            ->orderByDesc('id_bitacora')
            ->paginate($pageSize);

        return [
            'items' => $paginator->getCollection()
                ->map(fn (Bitacora $item): array => $this->format($item))
                ->values(),
            'metrics' => $metrics,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => $this->availableFilters(),
        ];
    }

    public function record(
        ?Usuario $actor,
        string $action,
        ?string $description = null,
        array $context = []
    ): Bitacora {
        return Bitacora::create([
            'usuario_id' => $actor?->id_usuario,
            'usuario_referencia_id' => $context['usuario_referencia_id'] ?? $actor?->id_usuario,
            'accion' => $action,
            'descripcion' => $description,
            'ip_address' => $context['ip_address'] ?? request()?->ip(),
            'user_agent' => $context['user_agent'] ?? request()?->userAgent(),
            'tabla_afectada' => $context['tabla_afectada'] ?? null,
            'registro_afectado_id' => $context['registro_afectado_id'] ?? null,
            'fecha' => $context['fecha'] ?? now(),
        ]);
    }

    private function buildQuery(array $filters = []): Builder
    {
        $query = Bitacora::query()
            ->with([
                'usuario:id_usuario,nombre,apellido,correo,rol,estado',
                'usuarioReferencia:id_usuario,nombre,apellido,correo,rol,estado',
            ]);

        $search = trim((string) ($filters['q'] ?? $filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('accion', 'ILIKE', "%{$search}%")
                    ->orWhere('descripcion', 'ILIKE', "%{$search}%")
                    ->orWhere('tabla_afectada', 'ILIKE', "%{$search}%")
                    ->orWhere('ip_address', 'ILIKE', "%{$search}%")
                    ->orWhereHas('usuario', function (Builder $userQuery) use ($search): void {
                        $userQuery
                            ->where('nombre', 'ILIKE', "%{$search}%")
                            ->orWhere('apellido', 'ILIKE', "%{$search}%")
                            ->orWhere('correo', 'ILIKE', "%{$search}%");
                    })
                    ->orWhereHas('usuarioReferencia', function (Builder $userQuery) use ($search): void {
                        $userQuery
                            ->where('nombre', 'ILIKE', "%{$search}%")
                            ->orWhere('apellido', 'ILIKE', "%{$search}%")
                            ->orWhere('correo', 'ILIKE', "%{$search}%");
                    });
            });
        }

        $action = trim((string) ($filters['accion'] ?? $filters['action'] ?? ''));
        if ($action !== '' && $action !== 'todos') {
            $query->where('accion', $action);
        }

        $module = trim((string) ($filters['modulo'] ?? $filters['module'] ?? ''));
        if ($module !== '' && $module !== 'todos') {
            $query->where('tabla_afectada', $module);
        }

        $userId = (int) ($filters['usuario_id'] ?? $filters['user_id'] ?? 0);
        if ($userId > 0) {
            $query->where(function (Builder $builder) use ($userId): void {
                $builder
                    ->where('usuario_id', $userId)
                    ->orWhere('usuario_referencia_id', $userId);
            });
        }

        if (! empty($filters['desde'] ?? $filters['from'] ?? null)) {
            $query->where('fecha', '>=', Carbon::parse($filters['desde'] ?? $filters['from'])->startOfDay());
        }

        if (! empty($filters['hasta'] ?? $filters['to'] ?? null)) {
            $query->where('fecha', '<=', Carbon::parse($filters['hasta'] ?? $filters['to'])->endOfDay());
        }

        return $query;
    }

    private function metrics(Builder $query): array
    {
        $clone = clone $query;
        $items = $clone->get(['id_bitacora', 'accion', 'tabla_afectada', 'fecha']);

        return [
            'total' => $items->count(),
            'today' => $items->filter(fn (Bitacora $item): bool => $item->fecha?->isToday() ?? false)->count(),
            'users' => $items->where('tabla_afectada', 'usuarios')->count(),
            'sessions' => $items->where('tabla_afectada', 'personal_access_tokens')->count(),
            'security' => $items->filter(fn (Bitacora $item): bool => str_contains((string) $item->accion, 'recuperacion')
                || str_contains((string) $item->accion, 'bloque')
                || str_contains((string) $item->accion, 'token'))->count(),
        ];
    }

    private function availableFilters(): array
    {
        return [
            'actions' => Bitacora::query()
                ->select('accion')
                ->whereNotNull('accion')
                ->distinct()
                ->orderBy('accion')
                ->pluck('accion')
                ->values(),
            'modules' => Bitacora::query()
                ->select('tabla_afectada')
                ->whereNotNull('tabla_afectada')
                ->distinct()
                ->orderBy('tabla_afectada')
                ->pluck('tabla_afectada')
                ->values(),
        ];
    }

    private function format(Bitacora $item): array
    {
        return [
            'id' => $item->id_bitacora,
            'accion' => $item->accion,
            'accionLabel' => $this->humanizeAction($item->accion),
            'descripcion' => $item->descripcion,
            'fecha' => $item->fecha?->format('Y-m-d\TH:i:s'),
            'fechaHumana' => $item->fecha?->format('d/m/Y H:i'),
            'ipAddress' => $item->ip_address,
            'userAgent' => $item->user_agent,
            'tablaAfectada' => $item->tabla_afectada,
            'moduloLabel' => $this->humanizeModule($item->tabla_afectada),
            'registroAfectadoId' => $item->registro_afectado_id,
            'actor' => $this->formatUser($item->usuario),
            'usuarioAfectado' => $this->formatUser($item->usuarioReferencia),
        ];
    }

    private function formatUser(?Usuario $usuario): ?array
    {
        if (! $usuario) {
            return null;
        }

        return [
            'id' => $usuario->id_usuario,
            'nombre' => trim($usuario->nombre.' '.$usuario->apellido),
            'correo' => $usuario->correo,
            'rol' => $usuario->rol,
            'estado' => $usuario->estado,
        ];
    }

    private function humanizeAction(?string $action): string
    {
        $value = trim((string) $action);

        if ($value === '') {
            return 'Accion registrada';
        }

        return ucfirst(str_replace('_', ' ', $value));
    }

    private function humanizeModule(?string $module): string
    {
        $value = trim((string) $module);

        if ($value === '') {
            return 'Sistema';
        }

        return ucfirst(str_replace('_', ' ', $value));
    }
}
