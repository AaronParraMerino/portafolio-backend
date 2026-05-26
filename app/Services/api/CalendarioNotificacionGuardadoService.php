<?php

namespace App\Services\api;

use App\Models\EventoPersonal;
use App\Models\Notificacion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CalendarioNotificacionGuardadoService
{
    /**
     * Guarda una notificación cuando el usuario crea un evento para hoy
     */
    public function notificarEventoCreadoParaHoy(mixed $evento): array
    {
        if (! $this->esEventoActivoDeHoy($evento)) {
            return $this->sinAccion('El evento no corresponde al día actual');
        }

        return $this->guardarNotificacionEventoHoy(
            evento: $evento,
            actorId: (int) $this->valor($evento, 'usuario_id'),
            origen: 'creado'
        );
    }

    /**
     * Guarda o actualiza una notificación si el evento editado queda para hoy
     */
    public function notificarEventoActualizadoParaHoy(mixed $evento): array
    {
        if (! $this->esEventoActivoDeHoy($evento)) {
            return $this->sinAccion('El evento actualizado no corresponde al día actual');
        }

        return $this->guardarNotificacionEventoHoy(
            evento: $evento,
            actorId: (int) $this->valor($evento, 'usuario_id'),
            origen: 'actualizado'
        );
    }

    /**
     * Guarda recordatorios de eventos del día para un usuario
     */
    public function notificarEventosDelDia(int $idUsuario, ?string $fecha = null): array
    {
        $fecha = $fecha ?: now()->toDateString();

        $eventos = EventoPersonal::query()
            ->where('usuario_id', $idUsuario)
            ->where('estado', 'activo')
            ->whereDate('fecha', $fecha)
            ->get();

        if ($eventos->isEmpty()) {
            return $this->sinAccion('No hay eventos para notificar');
        }

        $creadas = [];
        $errores = [];

        foreach ($eventos as $evento) {
            $resultado = $this->guardarNotificacionEventoHoy(
                evento: $evento,
                actorId: null,
                origen: 'recordatorio'
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
                ? 'Notificaciones de calendario guardadas correctamente'
                : 'Algunas notificaciones de calendario no pudieron guardarse',
            'creadas' => array_filter($creadas),
            'errores' => $errores,
        ];
    }

    /**
     * Guarda o actualiza la notificación del evento del día
     * Usa el mismo event_key para evitar duplicados
     */
    private function guardarNotificacionEventoHoy(mixed $evento, ?int $actorId, string $origen): array
    {
        $idEvento = (int) $this->valor($evento, 'id_evento');
        $idUsuario = (int) $this->valor($evento, 'usuario_id');
        $tituloEvento = trim((string) $this->valor($evento, 'titulo'));
        $fecha = $this->formatearFecha($this->valor($evento, 'fecha'));
        $hora = $this->formatearHora($this->valor($evento, 'hora'));
        $tipoEvento = (string) $this->valor($evento, 'tipo', 'otro');

        if ($idEvento <= 0 || $idUsuario <= 0 || $tituloEvento === '') {
            return $this->sinAccion('Datos insuficientes del evento');
        }

        $eventKey = 'calendar_event_today:event_' . $idEvento . ':user_' . $idUsuario . ':date_' . $fecha;

        return $this->crearOActualizar([
            'id_usuario_destino' => $idUsuario,
            'id_usuario_actor' => $actorId,

            'tipo' => 'calendar_event_today',
            'modulo' => 'calendario',

            'titulo' => 'Evento para hoy',
            'contenido' => 'Hoy tienes el evento "' . $tituloEvento . '" a las ' . $hora . '.',

            'referencia_tipo' => 'calendar_event',
            'referencia_id' => $idEvento,

            'data' => [
                'id_evento' => $idEvento,
                'titulo_evento' => $tituloEvento,
                'fecha' => $fecha,
                'hora' => $hora,
                'tipo_evento' => $tipoEvento,
                'origen' => $origen,
            ],

            'event_key' => $eventKey,
        ]);
    }

    /**
     * Crea la notificación si no existe
     * Si ya existe, actualiza su contenido para evitar datos viejos
     */
    private function crearOActualizar(array $data): array
    {
        $validacion = $this->validarDatos($data);

        if (! $validacion['status']) {
            return $validacion;
        }

        try {
            $notificacion = DB::transaction(function () use ($data) {
                $eventKey = $data['event_key'] ?? null;

                if ($eventKey) {
                    $existente = Notificacion::where('event_key', $eventKey)->first();

                    if ($existente) {
                        $existente->update([
                            'id_usuario_actor' => $data['id_usuario_actor'] ?? $existente->id_usuario_actor,
                            'tipo' => $data['tipo'],
                            'modulo' => $data['modulo'],
                            'titulo' => trim($data['titulo']),
                            'contenido' => isset($data['contenido']) ? trim($data['contenido']) : null,
                            'referencia_tipo' => $data['referencia_tipo'] ?? null,
                            'referencia_id' => $data['referencia_id'] ?? null,
                            'data' => $data['data'] ?? null,
                            'updated_at' => now(),
                        ]);

                        return $existente->fresh();
                    }
                }

                return Notificacion::create([
                    'id_usuario_destino' => $data['id_usuario_destino'],
                    'id_usuario_actor' => $data['id_usuario_actor'] ?? null,

                    'tipo' => $data['tipo'],
                    'modulo' => $data['modulo'],

                    'titulo' => trim($data['titulo']),
                    'contenido' => isset($data['contenido']) ? trim($data['contenido']) : null,

                    'referencia_tipo' => $data['referencia_tipo'] ?? null,
                    'referencia_id' => $data['referencia_id'] ?? null,

                    'data' => $data['data'] ?? null,
                    'event_key' => $eventKey,

                    'leida_en' => null,
                ]);
            });

            return [
                'status' => true,
                'message' => 'Notificación de calendario guardada correctamente',
                'notificacion' => $notificacion,
            ];
        } catch (QueryException $e) {
            if (($data['event_key'] ?? null) && $this->esErrorDuplicado($e)) {
                return [
                    'status' => true,
                    'message' => 'La notificación ya existe',
                    'notificacion' => Notificacion::where('event_key', $data['event_key'])->first(),
                ];
            }

            return [
                'status' => false,
                'message' => 'Error al guardar la notificación de calendario',
                'error' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Error al guardar la notificación de calendario',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verifica si el evento está activo y es para hoy
     */
    private function esEventoActivoDeHoy(mixed $evento): bool
    {
        $estado = (string) $this->valor($evento, 'estado', 'activo');
        $fecha = $this->valor($evento, 'fecha');

        if ($estado !== 'activo' || !$fecha) {
            return false;
        }

        return Carbon::parse($fecha)->isToday();
    }

    /**
     * Valida los datos mínimos para guardar una notificacion
     */
    private function validarDatos(array $data): array
    {
        foreach (['id_usuario_destino', 'tipo', 'modulo', 'titulo'] as $campo) {
            if (!isset($data[$campo]) || trim((string) $data[$campo]) === '') {
                return [
                    'status' => false,
                    'message' => 'El campo ' . $campo . ' es obligatorio',
                ];
            }
        }

        if (mb_strlen(trim($data['titulo'])) > 50) {
            return [
                'status' => false,
                'message' => 'El título no puede superar los 50 caracteres',
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
    private function formatearFecha(mixed $fecha): string
    {
        return Carbon::parse($fecha)->format('Y-m-d');
    }

    /**
     * Formatea hora como H:i
     */
    private function formatearHora(mixed $hora): string
    {
        return Carbon::parse($hora)->format('H:i');
    }

    /**
     * Detecta error de duplicado por indice unique
     */
    private function esErrorDuplicado(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Duplicate')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'unique')
            || str_contains($message, 'UNIQUE');
    }

    /**
     * Respuesta estándar cuando no corresponde guardar notificacion
     */
    private function sinAccion(string $message): array
    {
        return [
            'status' => true,
            'message' => $message,
            'notificacion' => null,
        ];
    }
}