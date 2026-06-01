<?php

namespace App\Services\api;

use App\Models\EventoPersonal;
use App\Models\Notificacion;
use App\Models\NotificacionUsuario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EventosNotificacionGuardadoService
{
    /**
     * Guarda o actualiza una notificacion cuando el usuario crea un evento personal para hoy o mañana
     */
    public function notificarEventoCreadoPersonal(mixed $evento): array
    {
        if (! $this->esEventoActivoDeHoyOMañana($evento)) {
            return $this->sinAccion('El evento no corresponde a hoy ni mañana');
        }

        return $this->guardarNotificacionEventoPersonal(
            evento: $evento,
            actorId: (int) $this->valor($evento, 'usuario_id')
        );
    }

    /**
     * Guarda o actualiza una notificacion si el evento personal editado queda para hoy o mañana
     */
    public function notificarEventoActualizadoPersonal(mixed $evento): array
    {
        if (! $this->esEventoActivoDeHoyOMañana($evento)) {
            return $this->sinAccion('El evento actualizado no corresponde a hoy ni mañana');
        }

        return $this->guardarNotificacionEventoPersonal(
            evento: $evento,
            actorId: (int) $this->valor($evento, 'usuario_id')
        );
    }

    /**
     * Guarda recordatorios personales de eventos de hoy para un usuario
     */
    public function notificarEventosPersonalesDeHoy(int $idUsuario): array
    {
        return $this->notificarEventosPersonalesPorFecha(
            idUsuario: $idUsuario,
            fecha: now()->toDateString()
        );
    }

    /**
     * Guarda recordatorios personales de eventos de mañana para un usuario
     */
    public function notificarEventosPersonalesDeManana(int $idUsuario): array
    {
        return $this->notificarEventosPersonalesPorFecha(
            idUsuario: $idUsuario,
            fecha: now()->addDay()->toDateString()
        );
    }

    /**
     * Guarda recordatorios personales de eventos para una fecha especifica
     */
    public function notificarEventosPersonalesPorFecha(int $idUsuario, string $fecha): array
    {
        $eventos = EventoPersonal::query()
            ->where('usuario_id', $idUsuario)
            ->where('estado', 'activo')
            ->whereDate('fecha', $fecha)
            ->get();

        if ($eventos->isEmpty()) {
            return $this->sinAccion('No hay eventos personales para notificar');
        }

        $creadas = [];
        $errores = [];

        foreach ($eventos as $evento) {
            $resultado = $this->guardarNotificacionEventoPersonal(
                evento: $evento,
                actorId: null
            );

            if ($resultado['status']) {
                $creadas[] = $resultado['notificacion'] ?? null;
            } else {
                $errores[] = $resultado;
            }
        }

        return [
            'status' => empty($errores),
            'message' => empty($errores)
                ? 'Notificaciones personales guardadas correctamente'
                : 'Algunas notificaciones personales no pudieron guardarse',
            'creadas' => array_values(array_filter($creadas)),
            'errores' => $errores,
        ];
    }

    /**
     * Guarda o actualiza una sola notificación personal por evento
     */
    private function guardarNotificacionEventoPersonal(mixed $evento, ?int $actorId): array
    {
        $idEvento = (int) $this->valor($evento, 'id_evento');
        $idUsuario = (int) $this->valor($evento, 'usuario_id');
        $tituloEvento = trim((string) $this->valor($evento, 'titulo'));
        $fecha = $this->formatearFecha($this->valor($evento, 'fecha'));

        if ($idEvento <= 0 || $idUsuario <= 0 || $tituloEvento === '' || ! $fecha) {
            return $this->sinAccion('Datos insuficientes del evento');
        }

        $mensaje = $this->crearMensajeEventoPersonal($fecha, $tituloEvento);

        if ($mensaje === null) {
            return $this->sinAccion('El evento no corresponde a hoy ni mañana');
        }

        $contextoReferencia = 'evento_personal_' . $idEvento;

        return $this->crearOActualizar([
            'id_usuario' => $idUsuario,
            'id_usuario_actor' => $actorId,

            'modulo' => 'eventos',
            'contexto_tipo' => 'evento_personal',
            'contexto_referencia' => $contextoReferencia,
            'grupo_titulo' => 'Personales',

            'tipo' => 'personal_event_reminder',
            'mensaje' => $mensaje,
        ]);
    }

    /**
     * Crea la notificación si no existe; si existe, actualiza el mensaje
     */
    private function crearOActualizar(array $data): array
    {
        $validacion = $this->validarDatos($data);

        if (! $validacion['status']) {
            return $validacion;
        }

        try {
            $notificacion = DB::transaction(function () use ($data) {
                $existente = Notificacion::query()
                    ->join('notificacion_usuario as nu', 'nu.id_notificacion', '=', 'notificaciones.id_notificacion')
                    ->where('nu.id_usuario', $data['id_usuario'])
                    ->where('notificaciones.modulo', $data['modulo'])
                    ->where('notificaciones.contexto_tipo', $data['contexto_tipo'])
                    ->where('notificaciones.contexto_referencia', $data['contexto_referencia'])
                    ->where('notificaciones.tipo', $data['tipo'])
                    ->select('notificaciones.*')
                    ->first();

                if ($existente) {
                    $mensajeAnterior = $existente->mensaje;

                    $existente->update([
                        'id_usuario_actor' => $data['id_usuario_actor'] ?? $existente->id_usuario_actor,
                        'modulo' => $data['modulo'],
                        'contexto_tipo' => $data['contexto_tipo'],
                        'contexto_referencia' => $data['contexto_referencia'],
                        'grupo_titulo' => $data['grupo_titulo'],
                        'tipo' => $data['tipo'],
                        'mensaje' => trim($data['mensaje']),
                    ]);

                    if ($mensajeAnterior !== trim($data['mensaje'])) {
                        NotificacionUsuario::where('id_notificacion', $existente->id_notificacion)
                            ->where('id_usuario', $data['id_usuario'])
                            ->update([
                                'leido_en' => null,
                            ]);
                    }

                    return $existente->fresh();
                }

                $notificacion = Notificacion::create([
                    'id_usuario_actor' => $data['id_usuario_actor'] ?? null,
                    'modulo' => $data['modulo'],
                    'contexto_tipo' => $data['contexto_tipo'],
                    'contexto_referencia' => $data['contexto_referencia'],
                    'grupo_titulo' => $data['grupo_titulo'],
                    'tipo' => $data['tipo'],
                    'mensaje' => trim($data['mensaje']),
                ]);

                NotificacionUsuario::create([
                    'id_notificacion' => $notificacion->id_notificacion,
                    'id_usuario' => $data['id_usuario'],
                    'leido_en' => null,
                ]);

                return $notificacion;
            });

            return [
                'status' => true,
                'message' => 'Notificación personal guardada correctamente',
                'notificacion' => $notificacion,
                'errores' => [],
            ];
        } catch (QueryException $e) {
            return [
                'status' => false,
                'message' => 'Error al guardar la notificación personal',
                'notificacion' => null,
                'errores' => [
                    [
                        'message' => $e->getMessage(),
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Error al guardar la notificación personal',
                'notificacion' => null,
                'errores' => [
                    [
                        'message' => $e->getMessage(),
                    ],
                ],
            ];
        }
    }

    /**
     * Verifica si el evento esta activo y es para hoy o mañana
     */
    private function esEventoActivoDeHoyOMañana(mixed $evento): bool
    {
        $estado = (string) $this->valor($evento, 'estado', 'activo');
        $fecha = $this->valor($evento, 'fecha');

        if ($estado !== 'activo' || ! $fecha) {
            return false;
        }

        $fechaEvento = Carbon::parse($fecha);

        return $fechaEvento->isToday() || $fechaEvento->isTomorrow();
    }

    /**
     * Crea el mensaje del evento personal segun la fecha
     */
    private function crearMensajeEventoPersonal(string $fecha, string $tituloEvento): ?string
    {
        $fechaEvento = Carbon::parse($fecha);

        if ($fechaEvento->isToday()) {
            return 'Hoy tienes ' . $tituloEvento . '.';
        }

        if ($fechaEvento->isTomorrow()) {
            return 'Mañana tienes ' . $tituloEvento . '.';
        }

        return null;
    }

    /**
     * Valida los datos minimos para guardar una notificacion
     */
    private function validarDatos(array $data): array
    {
        foreach (['id_usuario', 'modulo', 'contexto_tipo', 'contexto_referencia', 'grupo_titulo', 'tipo', 'mensaje'] as $campo) {
            if (! isset($data[$campo]) || trim((string) $data[$campo]) === '') {
                return [
                    'status' => false,
                    'message' => 'El campo ' . $campo . ' es obligatorio',
                ];
            }
        }

        if (mb_strlen(trim($data['modulo'])) > 50) {
            return [
                'status' => false,
                'message' => 'El módulo no puede superar los 50 caracteres',
            ];
        }

        if (mb_strlen(trim($data['contexto_tipo'])) > 50) {
            return [
                'status' => false,
                'message' => 'El contexto_tipo no puede superar los 50 caracteres',
            ];
        }

        if (mb_strlen(trim($data['contexto_referencia'])) > 100) {
            return [
                'status' => false,
                'message' => 'El contexto_referencia no puede superar los 100 caracteres',
            ];
        }

        if (mb_strlen(trim($data['grupo_titulo'])) > 150) {
            return [
                'status' => false,
                'message' => 'El grupo_titulo no puede superar los 150 caracteres',
            ];
        }

        if (mb_strlen(trim($data['tipo'])) > 80) {
            return [
                'status' => false,
                'message' => 'El tipo no puede superar los 80 caracteres',
            ];
        }

        return ['status' => true];
    }

    /**
     * Obtiene un valor desde modelo, objeto o array
     */
    private function valor(mixed $item, string $key, mixed $default = null): mixed
    {
        if (is_array($item)) {
            return $item[$key] ?? $default;
        }

        if (is_object($item)) {
            return $item->{$key} ?? $default;
        }

        return $default;
    }

    /**
     * Formatea fecha como Y-m-d
     */
    private function formatearFecha(mixed $fecha): ?string
    {
        if (! $fecha) {
            return null;
        }

        return Carbon::parse($fecha)->format('Y-m-d');
    }

    /**
     * Respuesta estandar cuando no corresponde guardar notificacion
     */
    private function sinAccion(string $message): array
    {
        return [
            'status' => true,
            'message' => $message,
            'notificacion' => null,
            'creadas' => [],
            'errores' => [],
        ];
    }
}