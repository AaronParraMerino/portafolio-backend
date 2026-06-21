<?php

namespace App\Services\api\Notificaciones;

use App\Models\Notificacion;
use App\Services\api\Mensajeria\MensajeriaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NotificacionAccionPersonalService
{
    private const MODULO_PERSONALES = 'personales';

    public function __construct(
        private readonly MensajeriaService $mensajeriaService
    ) {
    }

    public function responder(int $idUsuario, int $idNotificacion, string $accion): array
    {
        $accion = strtolower(trim($accion));

        if (! in_array($accion, ['aceptar', 'rechazar'], true)) {
            return [
                'status' => 'invalid_payload',
                'message' => 'Accion no valida',
            ];
        }

        $notificacion = Notificacion::query()
            ->where('id_notificacion', $idNotificacion)
            ->whereHas('usuarios', fn ($query) => $query->where('usuarios.id_usuario', $idUsuario))
            ->first();

        if (! $notificacion) {
            return [
                'status' => 'not_found',
                'message' => 'Notificacion no encontrada',
            ];
        }

        if ($notificacion->modulo !== self::MODULO_PERSONALES) {
            return [
                'status' => 'invalid_payload',
                'message' => 'La notificacion no pertenece a acciones personales',
            ];
        }

        if ($notificacion->accion_estado !== null && $notificacion->accion_estado !== 'pendiente') {
            return [
                'status' => 'invalid_state',
                'message' => 'La accion ya fue respondida',
                'data' => [
                    'accion_estado' => $notificacion->accion_estado,
                    'accion_respondida_at' => $this->formatUtcDateTime($notificacion->accion_respondida_at),
                ],
            ];
        }

        $contextoReferencia = (int) $notificacion->contexto_referencia;
        if ($contextoReferencia <= 0) {
            return [
                'status' => 'invalid_payload',
                'message' => 'La notificacion no tiene una referencia accionable',
            ];
        }

        $resultado = match ($notificacion->contexto_tipo) {
            'chat_solicitud' => $accion === 'aceptar'
                ? $this->mensajeriaService->aceptarSolicitud($idUsuario, $contextoReferencia)
                : $this->mensajeriaService->rechazarSolicitud($idUsuario, $contextoReferencia),
            'chat_invitacion' => $accion === 'aceptar'
                ? $this->mensajeriaService->aceptarInvitacion($idUsuario, $contextoReferencia)
                : $this->mensajeriaService->rechazarInvitacion($idUsuario, $contextoReferencia),
            default => [
                'status' => 'invalid_payload',
                'message' => 'Tipo de accion personal no soportado',
            ],
        };

        if (($resultado['status'] ?? null) === 'success') {
            $this->marcarNotificacionComoLeida($idUsuario, $idNotificacion);
        }

        return array_merge($resultado, [
            'notificacion' => $this->obtenerNotificacionDelUsuario($idUsuario, $idNotificacion),
            'resumen' => [
                'pendientes' => $this->contarNoLeidas($idUsuario),
            ],
        ]);
    }

    private function marcarNotificacionComoLeida(int $idUsuario, int $idNotificacion): void
    {
        DB::table('notificacion_usuario')
            ->where('id_usuario', $idUsuario)
            ->where('id_notificacion', $idNotificacion)
            ->whereNull('leido_en')
            ->update([
                'leido_en' => now(),
                'updated_at' => now(),
            ]);
    }

    private function contarNoLeidas(int $idUsuario): int
    {
        return DB::table('notificacion_usuario')
            ->where('id_usuario', $idUsuario)
            ->whereNull('leido_en')
            ->count();
    }

    private function obtenerNotificacionDelUsuario(int $idUsuario, int $idNotificacion): ?array
    {
        $row = DB::table('notificacion_usuario as nu')
            ->join('notificaciones as n', 'n.id_notificacion', '=', 'nu.id_notificacion')
            ->leftJoin('usuarios as actor', 'actor.id_usuario', '=', 'n.id_usuario_actor')
            ->where('nu.id_usuario', $idUsuario)
            ->where('n.id_notificacion', $idNotificacion)
            ->select([
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
            ])
            ->first();

        if (! $row) {
            return null;
        }

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

    private function formatUtcDateTime(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value, config('app.timezone'))
            ->utc()
            ->format('Y-m-d\TH:i:s\Z');
    }
}
