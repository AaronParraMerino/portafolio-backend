<?php

namespace App\Services\api\Notificaciones;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NotificacionSupportService
{
    public const MODULO_PROYECTOS = 'proyectos';
    public const MODULO_EVENTOS = 'eventos';
    public const MODULO_ADMINISTRACION = 'administracion';
    public const MODULO_PERSONALES = 'personales';

    public function consultaBaseNoLeidas(int $idUsuario)
    {
        return $this->consultaBaseUsuario($idUsuario)
            ->whereNull('nu.leido_en');
    }

    public function consultaBaseLeidas(int $idUsuario)
    {
        return $this->consultaBaseUsuario($idUsuario)
            ->whereNotNull('nu.leido_en');
    }

    public function consultaBaseUsuario(int $idUsuario)
    {
        return DB::table('notificacion_usuario as nu')
            ->join('notificaciones as n', 'n.id_notificacion', '=', 'nu.id_notificacion')
            ->leftJoin('usuarios as actor', 'actor.id_usuario', '=', 'n.id_usuario_actor')
            ->where('nu.id_usuario', $idUsuario);
    }

    public function camposMensaje(): array
    {
        return [
            'n.id_notificacion',
            'nu.id_notificacion_usuario',
            'n.id_usuario_actor',
            'n.modulo',
            'n.tipo',
            'n.mensaje',
            'n.contexto_referencia',
            'n.grupo_titulo',
            'n.accion_estado',
            'n.accion_respuesta',
            'n.accion_respondida_at',
            'n.accion_disponible_nuevamente_at',
            'n.metadata',
            'n.created_at',
            'nu.leido_en',
            'actor.nombre as actor_nombre',
            'actor.apellido as actor_apellido',
            'actor.correo as actor_correo',
        ];
    }

    public function formatearMensaje(object $row): array
    {
        return [
            'id_notificacion' => (int) $row->id_notificacion,
            'id_notificacion_usuario' => (int) $row->id_notificacion_usuario,
            'id_usuario_actor' => $row->id_usuario_actor ? (int) $row->id_usuario_actor : null,
            'modulo' => $row->modulo,
            'tipo' => $row->tipo,
            'mensaje' => $row->mensaje,
            'contexto_referencia' => $row->contexto_referencia,
            'grupo_titulo' => $row->grupo_titulo,
            'accion_estado' => $row->accion_estado,
            'accion_respuesta' => $row->accion_respuesta,
            'accion_respondida_at' => $this->formatUtcDateTime($row->accion_respondida_at),
            'accion_disponible_nuevamente_at' => $this->formatUtcDateTime($row->accion_disponible_nuevamente_at),
            'metadata' => is_string($row->metadata)
                ? (json_decode($row->metadata, true) ?: [])
                : ($row->metadata ?? []),
            'created_at' => $this->formatUtcDateTime($row->created_at),
            'leido_en' => $this->formatUtcDateTime($row->leido_en),
            'actor' => $row->id_usuario_actor ? [
                'id_usuario' => (int) $row->id_usuario_actor,
                'nombre' => trim(($row->actor_nombre ?? '') . ' ' . ($row->actor_apellido ?? '')),
                'correo' => $row->actor_correo,
            ] : null,
        ];
    }

    public function obtenerNotificacionDelUsuario(int $idUsuario, int $idNotificacion): ?array
    {
        $row = $this->consultaBaseUsuario($idUsuario)
            ->where('n.id_notificacion', $idNotificacion)
            ->select($this->camposMensaje())
            ->first();

        return $row ? $this->formatearMensaje($row) : null;
    }

    public function paginarMensajes($query, int $porPagina): array
    {
        $paginador = $query->paginate($porPagina);

        return [
            'data' => collect($paginador->items())
                ->map(fn ($row) => $this->formatearMensaje($row))
                ->values()
                ->all(),
            'meta' => [
                'total' => $paginador->total(),
                'per_page' => $paginador->perPage(),
                'current_page' => $paginador->currentPage(),
                'last_page' => $paginador->lastPage(),
                'has_more_pages' => $paginador->hasMorePages(),
            ],
        ];
    }

    public function modulosBase(): array
    {
        return [
            [
                'modulo' => self::MODULO_PROYECTOS,
                'titulo' => 'Proyectos',
            ],
            [
                'modulo' => self::MODULO_EVENTOS,
                'titulo' => 'Eventos',
            ],
            [
                'modulo' => self::MODULO_ADMINISTRACION,
                'titulo' => 'Administracion',
            ],
            [
                'modulo' => self::MODULO_PERSONALES,
                'titulo' => 'Personales',
            ],
        ];
    }

    public function normalizarModulo(string $modulo): string
    {
        $modulo = strtolower(trim($modulo));

        return match ($modulo) {
            'proyecto', 'proyectos' => self::MODULO_PROYECTOS,
            'evento', 'eventos' => self::MODULO_EVENTOS,
            'admin', 'administracion' => self::MODULO_ADMINISTRACION,
            'personal', 'personales' => self::MODULO_PERSONALES,
            default => $modulo,
        };
    }

    public function moduloValido(string $modulo): bool
    {
        return in_array($modulo, [
            self::MODULO_PROYECTOS,
            self::MODULO_EVENTOS,
            self::MODULO_ADMINISTRACION,
            self::MODULO_PERSONALES,
        ], true);
    }

    public function formatUtcDateTime(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value, config('app.timezone'))
            ->utc()
            ->format('Y-m-d\TH:i:s\Z');
    }
}
