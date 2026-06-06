<?php

namespace App\Services\api;

use App\Models\EventoInscripcion;
use App\Models\EventoPersonal;
use App\Models\Notificacion;
use App\Models\NotificacionUsuario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EventosNotificacionGuardadoService
{
    /**
     * Guarda o actualiza una notificacion cuando el usuario crea un evento personal para hoy o el dia siguiente
     */
    public function notificarEventoCreadoPersonal(mixed $evento): array
    {
        if (! $this->esEventoPersonalActivoDeHoyOManana($evento)) {
            return $this->sinAccion('El evento no corresponde a hoy ni mañana');
        }

        return $this->guardarNotificacionEventoPersonal(
            evento: $evento,
            actorId: (int) $this->valor($evento, 'usuario_id')
        );
    }

    /**
     * Guarda o actualiza una notificacion si el evento personal editado queda para hoy o el dia siguiente
     */
    public function notificarEventoActualizadoPersonal(mixed $evento): array
    {
        if (! $this->esEventoPersonalActivoDeHoyOManana($evento)) {
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
        return $this->notificarEventosPersonalesPorMomento(
            idUsuario: $idUsuario,
            momento: 'hoy'
        );
    }

    /**
     * Guarda recordatorios personales de eventos para el dia siguiente para un usuario
     */
    public function notificarEventosPersonalesDeManana(int $idUsuario): array
    {
        return $this->notificarEventosPersonalesPorMomento(
            idUsuario: $idUsuario,
            momento: 'manana'
        );
    }

    /**
     * Genera notificaciones de eventos inscritos para todos los usuarios.
     *
     * Solo revisa:
     * - eventos inscritos de hoy
     * - eventos inscritos para el dia siguiente
     */
    public function generarNotificacionesEventosProximos(): array
    {
        $hoy = $this->generarNotificacionesEventosInscritosPorMomento('hoy');
        $manana = $this->generarNotificacionesEventosInscritosPorMomento('manana');

        return [
            'status' => $hoy['status'] && $manana['status'],
            'message' => 'Proceso de notificaciones de eventos inscritos finalizado',
            'hoy' => $hoy,
            'manana' => $manana,
            'total_notificaciones_creadas' =>
                ($hoy['notificaciones_creadas'] ?? 0) +
                ($manana['notificaciones_creadas'] ?? 0),
            'total_notificaciones_actualizadas' =>
                ($hoy['notificaciones_actualizadas'] ?? 0) +
                ($manana['notificaciones_actualizadas'] ?? 0),
            'total_destinatarios' =>
                ($hoy['destinatarios'] ?? 0) +
                ($manana['destinatarios'] ?? 0),
            'errores' => array_merge(
                $hoy['errores'] ?? [],
                $manana['errores'] ?? []
            ),
        ];
    }

    /**
     * Genera notificaciones de eventos personales e inscritos para hoy y mañana.
     */
    public function generarNotificacionesGeneralesProximas(int $idUsuario): array
    {
        $personalesHoy = $this->notificarEventosPersonalesDeHoy($idUsuario);
        $personalesManana = $this->notificarEventosPersonalesDeManana($idUsuario);
        $inscritos = $this->generarNotificacionesEventosProximos();

        return [
            'status' =>
                ($personalesHoy['status'] ?? false) &&
                ($personalesManana['status'] ?? false) &&
                ($inscritos['status'] ?? false),
            'message' => 'Proceso de notificaciones generales de eventos finalizado',
            'personales' => [
                'hoy' => $personalesHoy,
                'manana' => $personalesManana,
            ],
            'inscritos' => $inscritos,
        ];
    }

    /**
     * Busca eventos personales solo de hoy o el dia siguiente
     */
    private function notificarEventosPersonalesPorMomento(int $idUsuario, string $momento): array
    {
        $fecha = $this->fechaPorMomento($momento);

        $eventos = EventoPersonal::query()
            ->where('usuario_id', $idUsuario)
            ->where('estado', 'activo')
            ->whereDate('fecha', $fecha->toDateString())
            ->get();

        if ($eventos->isEmpty()) {
            return $this->sinAccion(
                $momento === 'hoy'
                    ? 'No hay eventos personales para hoy'
                    : 'No hay eventos personales para mañana'
            );
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
     * Busca eventos inscritos solo para hoy o el dia siguiente
     *
     * Usa:
     * - evento_inscripciones.estado = inscrito
     * - evento_inscripciones.fecha_desinscripcion IS NULL
     * - admin_eventos.fecha_inicio = hoy o el dia siguiente
     */
    private function generarNotificacionesEventosInscritosPorMomento(string $momento): array
    {
        $fecha = $this->fechaPorMomento($momento);

        $inscripciones = EventoInscripcion::query()
            ->with('evento')
            ->where('estado', 'inscrito')
            ->whereNull('fecha_desinscripcion')
            ->whereHas('evento', function ($query) use ($fecha) {
                $query
                    ->whereDate('fecha_inicio', $fecha->toDateString())
                    ->whereNotIn('estado', [
                        'cancelado',
                        'eliminado',
                        'inactivo',
                    ]);
            })
            ->get();

        if ($inscripciones->isEmpty()) {
            return [
                'status' => true,
                'message' => $momento === 'hoy'
                    ? 'No hay eventos inscritos para hoy'
                    : 'No hay eventos inscritos para mañana',
                'notificaciones_creadas' => 0,
                'notificaciones_actualizadas' => 0,
                'destinatarios' => 0,
                'errores' => [],
            ];
        }

        $notificacionesCreadas = 0;
        $notificacionesActualizadas = 0;
        $destinatarios = 0;
        $errores = [];

        $gruposPorEvento = $inscripciones->groupBy('evento_id');

        foreach ($gruposPorEvento as $items) {
            $evento = $items->first()?->evento;

            $usuarios = $items
                ->pluck('usuario_id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();

            $resultado = $this->guardarNotificacionEventoInscrito(
                evento: $evento,
                usuarios: $usuarios,
                momento: $momento
            );

            if (! $resultado['status']) {
                $errores[] = $resultado;
                continue;
            }

            if ($resultado['fue_creada'] ?? false) {
                $notificacionesCreadas++;
            }

            if ($resultado['fue_actualizada'] ?? false) {
                $notificacionesActualizadas++;
            }

            $destinatarios += $resultado['destinatarios'] ?? 0;
        }

        return [
            'status' => empty($errores),
            'message' => empty($errores)
                ? 'Notificaciones de eventos inscritos generadas correctamente'
                : 'Algunas notificaciones de eventos inscritos no pudieron generarse',
            'notificaciones_creadas' => $notificacionesCreadas,
            'notificaciones_actualizadas' => $notificacionesActualizadas,
            'destinatarios' => $destinatarios,
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
            return $this->sinAccion('Datos insuficientes del evento personal');
        }

        $mensaje = $this->crearMensajeEventoPersonal($fecha, $tituloEvento);

        if ($mensaje === null) {
            return $this->sinAccion('El evento personal no corresponde a hoy ni mañana');
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
     * Guarda o actualiza una notificación de evento inscrito
     */
    private function guardarNotificacionEventoInscrito(
        mixed $evento,
        array $usuarios,
        string $momento
    ): array {
        if (! $evento) {
            return $this->error('El evento inscrito no existe');
        }

        $idEvento = (int) $this->valor($evento, 'id_evento');
        $tituloEvento = trim((string) $this->valor($evento, 'titulo'));
        $fecha = $this->formatearFecha($this->valor($evento, 'fecha_inicio'));

        if ($idEvento <= 0 || $tituloEvento === '' || ! $fecha) {
            return $this->error('Datos insuficientes del evento inscrito');
        }

        if (empty($usuarios)) {
            return $this->sinAccion('El evento no tiene usuarios inscritos activos');
        }

        $mensaje = $this->crearMensajeEventoInscrito($fecha, $tituloEvento);

        if ($mensaje === null) {
            return $this->sinAccion('El evento inscrito no corresponde a hoy ni mañana');
        }

        $tipo = $momento === 'hoy'
            ? 'evento_inscrito_recordatorio_hoy'
            : 'evento_inscrito_recordatorio_manana';

        $contextoReferencia = 'evento_inscrito_' . $idEvento;

        return $this->crearOActualizarParaUsuarios([
            'id_usuario_actor' => null,

            'modulo' => 'eventos',
            'contexto_tipo' => 'evento_inscrito',
            'contexto_referencia' => $contextoReferencia,
            'grupo_titulo' => $tituloEvento,

            'tipo' => $tipo,
            'mensaje' => $mensaje,
        ], $usuarios);
    }

    /**
     * Crea la notificación para un solo usuario
     */
    private function crearOActualizar(array $data): array
    {
        $validacion = $this->validarDatos($data, true);

        if (! $validacion['status']) {
            return $validacion;
        }

        return $this->crearOActualizarParaUsuarios(
            data: $data,
            usuarios: [(int) $data['id_usuario']]
        );
    }

    /**
     * Crea una notificación y la relaciona con varios usuarios
     */
    private function crearOActualizarParaUsuarios(array $data, array $usuarios): array
    {
        $validacion = $this->validarDatos($data, false);

        if (! $validacion['status']) {
            return $validacion;
        }

        $usuarios = collect($usuarios)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($usuarios)) {
            return $this->sinAccion('No hay usuarios destinatarios validos');
        }

        try {
            $resultado = DB::transaction(function () use ($data, $usuarios): array {
                $busqueda = [
                    'modulo' => trim((string) $data['modulo']),
                    'contexto_tipo' => trim((string) $data['contexto_tipo']),
                    'contexto_referencia' => trim((string) $data['contexto_referencia']),
                    'tipo' => trim((string) $data['tipo']),
                ];

                $notificacion = Notificacion::query()
                    ->where($busqueda)
                    ->first();

                $fueCreada = false;
                $fueActualizada = false;
                $mensajeCambio = false;

                if ($notificacion) {
                    $mensajeAnterior = trim((string) $notificacion->mensaje);
                    $mensajeNuevo = trim((string) $data['mensaje']);

                    $notificacion->update([
                        'id_usuario_actor' => $data['id_usuario_actor'] ?? $notificacion->id_usuario_actor,
                        'modulo' => $busqueda['modulo'],
                        'contexto_tipo' => $busqueda['contexto_tipo'],
                        'contexto_referencia' => $busqueda['contexto_referencia'],
                        'grupo_titulo' => trim((string) $data['grupo_titulo']),
                        'tipo' => $busqueda['tipo'],
                        'mensaje' => $mensajeNuevo,
                    ]);

                    $mensajeCambio = $mensajeAnterior !== $mensajeNuevo;
                    $fueActualizada = true;
                } else {
                    $notificacion = Notificacion::create([
                        'id_usuario_actor' => $data['id_usuario_actor'] ?? null,
                        'modulo' => $busqueda['modulo'],
                        'contexto_tipo' => $busqueda['contexto_tipo'],
                        'contexto_referencia' => $busqueda['contexto_referencia'],
                        'grupo_titulo' => trim((string) $data['grupo_titulo']),
                        'tipo' => $busqueda['tipo'],
                        'mensaje' => trim((string) $data['mensaje']),
                    ]);

                    $fueCreada = true;
                }

                foreach ($usuarios as $idUsuario) {
                    NotificacionUsuario::firstOrCreate(
                        [
                            'id_notificacion' => $notificacion->id_notificacion,
                            'id_usuario' => $idUsuario,
                        ],
                        [
                            'leido_en' => null,
                        ]
                    );
                }

                /*
                 * Mejora:
                 * Si alguien se desinscribió, ya no queda como destinatario
                 * de esta notificación de evento inscrito.
                 */
                NotificacionUsuario::query()
                    ->where('id_notificacion', $notificacion->id_notificacion)
                    ->whereNotIn('id_usuario', $usuarios)
                    ->delete();

                /*
                 * Si el mensaje cambió, se marca como no leído otra vez.
                 * Si el job se ejecuta varias veces y el mensaje es igual,
                 * no se resetea leido_en.
                 */
                if ($mensajeCambio) {
                    NotificacionUsuario::query()
                        ->where('id_notificacion', $notificacion->id_notificacion)
                        ->whereIn('id_usuario', $usuarios)
                        ->update([
                            'leido_en' => null,
                        ]);
                }

                return [
                    'notificacion' => $notificacion->fresh(),
                    'fue_creada' => $fueCreada,
                    'fue_actualizada' => $fueActualizada,
                    'destinatarios' => count($usuarios),
                ];
            });

            return [
                'status' => true,
                'message' => 'Notificación guardada correctamente',
                'notificacion' => $resultado['notificacion'],
                'fue_creada' => $resultado['fue_creada'],
                'fue_actualizada' => $resultado['fue_actualizada'],
                'destinatarios' => $resultado['destinatarios'],
                'errores' => [],
            ];
        } catch (QueryException $e) {
            return $this->error('Error al guardar la notificación', $e);
        } catch (\Throwable $e) {
            return $this->error('Error al guardar la notificación', $e);
        }
    }

    /**
     * Verifica si el evento personal esta activo y es para hoy o el dia siguiente
     */
    private function esEventoPersonalActivoDeHoyOManana(mixed $evento): bool
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
     * Retorna la fecha correspondiente al momento permitido
     */
    private function fechaPorMomento(string $momento): Carbon
    {
        return $momento === 'hoy'
            ? Carbon::today()
            : Carbon::tomorrow();
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
     * Crea el mensaje del evento inscrito segun la fecha
     */
    private function crearMensajeEventoInscrito(string $fecha, string $tituloEvento): ?string
    {
        $fechaEvento = Carbon::parse($fecha);

        if ($fechaEvento->isToday()) {
            return 'Hoy tienes el evento ' . $tituloEvento . '.';
        }

        if ($fechaEvento->isTomorrow()) {
            return 'Mañana tienes el evento ' . $tituloEvento . '.';
        }

        return null;
    }

    /**
     * Valida los datos minimos para guardar una notificacion
     */
    private function validarDatos(array $data, bool $requiereUsuario): array
    {
        $campos = [
            'modulo',
            'contexto_tipo',
            'contexto_referencia',
            'grupo_titulo',
            'tipo',
            'mensaje',
        ];

        if ($requiereUsuario) {
            $campos[] = 'id_usuario';
        }

        foreach ($campos as $campo) {
            if (! isset($data[$campo]) || trim((string) $data[$campo]) === '') {
                return [
                    'status' => false,
                    'message' => 'El campo ' . $campo . ' es obligatorio',
                    'notificacion' => null,
                    'errores' => [],
                ];
            }
        }

        if ($requiereUsuario && (int) $data['id_usuario'] <= 0) {
            return [
                'status' => false,
                'message' => 'El id_usuario no es valido',
                'notificacion' => null,
                'errores' => [],
            ];
        }

        if (mb_strlen(trim((string) $data['modulo'])) > 50) {
            return [
                'status' => false,
                'message' => 'El módulo no puede superar los 50 caracteres',
                'notificacion' => null,
                'errores' => [],
            ];
        }

        if (mb_strlen(trim((string) $data['contexto_tipo'])) > 50) {
            return [
                'status' => false,
                'message' => 'El contexto_tipo no puede superar los 50 caracteres',
                'notificacion' => null,
                'errores' => [],
            ];
        }

        if (mb_strlen(trim((string) $data['contexto_referencia'])) > 100) {
            return [
                'status' => false,
                'message' => 'El contexto_referencia no puede superar los 100 caracteres',
                'notificacion' => null,
                'errores' => [],
            ];
        }

        if (mb_strlen(trim((string) $data['grupo_titulo'])) > 150) {
            return [
                'status' => false,
                'message' => 'El grupo_titulo no puede superar los 150 caracteres',
                'notificacion' => null,
                'errores' => [],
            ];
        }

        if (mb_strlen(trim((string) $data['tipo'])) > 80) {
            return [
                'status' => false,
                'message' => 'El tipo no puede superar los 80 caracteres',
                'notificacion' => null,
                'errores' => [],
            ];
        }

        return ['status' => true];
    }

    /**
     * Obtiene un valor desde modelo, objeto o array.
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
     * Formatea fecha como Y-m-d.
     */
    private function formatearFecha(mixed $fecha): ?string
    {
        if (! $fecha) {
            return null;
        }

        return Carbon::parse($fecha)->format('Y-m-d');
    }

    /**
     * Respuesta estandar cuando no corresponde guardar notificacion.
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

    /**
     * Respuesta estandar de error.
     */
    private function error(string $message, ?\Throwable $e = null): array
    {
        return [
            'status' => false,
            'message' => $message,
            'notificacion' => null,
            'errores' => $e ? [
                [
                    'message' => $e->getMessage(),
                ],
            ] : [],
        ];
    }
}
