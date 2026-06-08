<?php

namespace App\Services\api;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificacionService
{

    public function __construct(
        private readonly EventosNotificacionGuardadoService $eventosNotificacionGuardadoService
    ) {
    }

    private const MODULO_PROYECTOS = 'proyectos';
    private const MODULO_EVENTOS = 'eventos';
    private const MODULO_ADMINISTRACION = 'administracion';

    /**
     * Nivel 1 devuelve cantidad de notificaciones no leidas por modulo
     */
    public function obtenerResumenModulosNoLeidos(int $idUsuario): array
    {

        $this->generarNotificacionesEventosProximos($idUsuario);

        $conteos = $this->consultaBaseNoLeidas($idUsuario)
            ->select('n.modulo', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('n.modulo')
            ->pluck('cantidad', 'modulo');

        $data = collect($this->modulosBase())
            ->map(function (array $modulo) use ($conteos) {
                $cantidad = (int) ($conteos[$modulo['modulo']] ?? 0);

                return [
                    'modulo' => $modulo['modulo'],
                    'titulo' => $modulo['titulo'],
                    'cantidad' => $cantidad,
                ];
            })
            ->values()
            ->all();

        return [
            'status' => 'success',
            'data' => $data,
            'total' => collect($data)->sum('cantidad'),
        ];
    }

    /**
     * Nivel 2 devuelve grupos no leidos de un modulo
     */
    public function obtenerSegundoNivelPorModulo(int $idUsuario, string $modulo): array
    {
        $modulo = $this->normalizarModulo($modulo);

        if (!$this->moduloValido($modulo)) {
            return [
                'status' => 'invalid_module',
                'message' => 'Modulo no valido',
            ];
        }

        if ($modulo === self::MODULO_ADMINISTRACION) {
            return [
                'status' => 'success',
                'modulo' => $modulo,
                'tipo_vista' => 'mensajes_directos',
                'data' => $this->obtenerMensajesAdministracionNoLeidos($idUsuario),
            ];
        }

        $grupos = $this->consultaBaseNoLeidas($idUsuario)
            ->where('n.modulo', $modulo)
            ->select(
                'n.contexto_referencia',
                'n.grupo_titulo',
                DB::raw('COUNT(*) as cantidad'),
                DB::raw('MAX(n.created_at) as ultimo_creado_en')
            )
            ->groupBy('n.contexto_referencia', 'n.grupo_titulo')
            ->orderByDesc('ultimo_creado_en')
            ->get()
            ->map(function ($row) {
                return [
                    'contexto_referencia' => $row->contexto_referencia,
                    'titulo' => $row->grupo_titulo ?: 'Sin grupo',
                    'cantidad' => (int) $row->cantidad,
                ];
            })
            ->values()
            ->all();

        return [
            'status' => 'success',
            'modulo' => $modulo,
            'tipo_vista' => 'grupos',
            'data' => $grupos,
            'total' => collect($grupos)->sum('cantidad'),
        ];
    }

    /**
     * Nivel 3 devuelve mensajes individuales no leidos de un grupo
     */
    public function obtenerMensajesNoLeidosPorGrupo(
        int $idUsuario,
        string $modulo,
        string $contextoReferencia
    ): array {
        $modulo = $this->normalizarModulo($modulo);
        $contextoReferencia = trim($contextoReferencia);

        if (!$this->moduloValido($modulo)) {
            return [
                'status' => 'invalid_module',
                'message' => 'Modulo no valido',
            ];
        }

        if ($contextoReferencia === '') {
            return [
                'status' => 'invalid_payload',
                'message' => 'Debe enviar una referencia de grupo',
            ];
        }

        $mensajes = $this->consultaBaseNoLeidas($idUsuario)
            ->where('n.modulo', $modulo)
            ->where('n.contexto_referencia', $contextoReferencia)
            ->orderByDesc('n.created_at')
            ->select($this->camposMensaje())
            ->get()
            ->map(fn ($row) => $this->formatearMensaje($row))
            ->values()
            ->all();

        return [
            'status' => 'success',
            'modulo' => $modulo,
            'contexto_referencia' => $contextoReferencia,
            'data' => $mensajes,
            'total' => count($mensajes),
        ];
    }

    /**
     * Marca una notificacion como leida para el usuario
     */
    public function marcarNotificacionComoLeida(int $idUsuario, int $idNotificacion): array
    {
        $actualizadas = DB::table('notificacion_usuario')
            ->where('id_usuario', $idUsuario)
            ->where('id_notificacion', $idNotificacion)
            ->whereNull('leido_en')
            ->update([
                'leido_en' => now(),
                'updated_at' => now(),
            ]);

        if ($actualizadas <= 0) {
            $notificacion = $this->obtenerNotificacionDelUsuario($idUsuario, $idNotificacion);
            if ($notificacion) {
                return [
                    'status' => 'success',
                    'message' => 'Notificacion ya estaba leida',
                    'actualizadas' => 0,
                    'data' => $notificacion,
                    'resumen' => [
                        'pendientes' => $this->contarNoLeidas($idUsuario),
                    ],
                ];
            }

            return [
                'status' => 'not_found',
                'message' => 'Notificacion no encontrada o ya estaba leida',
                'actualizadas' => 0,
            ];
        }

        $notificacion = $this->obtenerNotificacionDelUsuario($idUsuario, $idNotificacion);

        return [
            'status' => 'success',
            'message' => 'Notificacion marcada como leida',
            'actualizadas' => $actualizadas,
            'data' => $notificacion,
            'resumen' => [
                'pendientes' => $this->contarNoLeidas($idUsuario),
            ],
        ];
    }

    /**
     * Marca una notificacion como no leida para que el usuario la revise despues
     */
    public function marcarNotificacionComoNoLeida(int $idUsuario, int $idNotificacion): array
    {
        $actualizadas = DB::table('notificacion_usuario')
            ->where('id_usuario', $idUsuario)
            ->where('id_notificacion', $idNotificacion)
            ->whereNotNull('leido_en')
            ->update([
                'leido_en' => null,
                'updated_at' => now(),
            ]);

        if ($actualizadas <= 0) {
            $notificacion = $this->obtenerNotificacionDelUsuario($idUsuario, $idNotificacion);
            if ($notificacion) {
                return [
                    'status' => 'success',
                    'message' => 'Notificacion ya estaba pendiente',
                    'actualizadas' => 0,
                    'data' => $notificacion,
                    'resumen' => [
                        'pendientes' => $this->contarNoLeidas($idUsuario),
                    ],
                ];
            }

            return [
                'status' => 'not_found',
                'message' => 'Notificacion no encontrada o ya estaba pendiente',
                'actualizadas' => 0,
            ];
        }

        $notificacion = $this->obtenerNotificacionDelUsuario($idUsuario, $idNotificacion);

        return [
            'status' => 'success',
            'message' => 'Notificacion marcada como no leida',
            'actualizadas' => $actualizadas,
            'data' => $notificacion,
            'resumen' => [
                'pendientes' => $this->contarNoLeidas($idUsuario),
            ],
        ];
    }

    /**
     * Marca como leidas todas las notificaciones de un grupo
     */
    public function marcarGrupoComoLeido(
        int $idUsuario,
        string $modulo,
        string $contextoReferencia
    ): array {
        $modulo = $this->normalizarModulo($modulo);
        $contextoReferencia = trim($contextoReferencia);

        if (!$this->moduloValido($modulo)) {
            return [
                'status' => 'invalid_module',
                'message' => 'Modulo no valido',
            ];
        }

        if ($contextoReferencia === '') {
            return [
                'status' => 'invalid_payload',
                'message' => 'Debe enviar una referencia de grupo',
            ];
        }

        $idsPivot = $this->consultaBaseNoLeidas($idUsuario)
            ->where('n.modulo', $modulo)
            ->where('n.contexto_referencia', $contextoReferencia)
            ->pluck('nu.id_notificacion_usuario');

        $actualizadas = $this->marcarPivotsComoLeidos($idsPivot);

        return [
            'status' => 'success',
            'message' => 'Grupo marcado como leido',
            'actualizadas' => $actualizadas,
            'resumen' => [
                'pendientes' => $this->contarNoLeidas($idUsuario),
            ],
        ];
    }

    /**
     * Marca como leidas todas las notificaciones no leidas de un modulo
     */
    public function marcarModuloComoLeido(int $idUsuario, string $modulo): array
    {
        $modulo = $this->normalizarModulo($modulo);

        if (!$this->moduloValido($modulo)) {
            return [
                'status' => 'invalid_module',
                'message' => 'Modulo no valido',
            ];
        }

        $idsPivot = $this->consultaBaseNoLeidas($idUsuario)
            ->where('n.modulo', $modulo)
            ->pluck('nu.id_notificacion_usuario');

        $actualizadas = $this->marcarPivotsComoLeidos($idsPivot);

        return [
            'status' => 'success',
            'message' => 'Modulo marcado como leido',
            'actualizadas' => $actualizadas,
            'resumen' => [
                'pendientes' => $this->contarNoLeidas($idUsuario),
            ],
        ];
    }

    /**
     * Marca como leidas todas las notificaciones pendientes del usuario
     */
    public function marcarTodasComoLeidas(int $idUsuario): array
    {
        $actualizadas = DB::table('notificacion_usuario')
            ->where('id_usuario', $idUsuario)
            ->whereNull('leido_en')
            ->update([
                'leido_en' => now(),
                'updated_at' => now(),
            ]);

        return [
            'status' => 'success',
            'message' => 'Todas las notificaciones fueron marcadas como leidas',
            'actualizadas' => $actualizadas,
            'resumen' => [
                'pendientes' => 0,
            ],
        ];
    }

    /**
     * Cuenta todas las notificaciones no leidas del usuario
     */
    public function contarNoLeidas(int $idUsuario): int
    {
        return DB::table('notificacion_usuario')
            ->where('id_usuario', $idUsuario)
            ->whereNull('leido_en')
            ->count();
    }

    /**
     * Lista las notificaciones leidas con paginacion para la vista estable del modal
     */
    public function obtenerNotificacionesLeidas(
        int $idUsuario,
        ?string $modulo = null,
        int $porPagina = 20
    ): array {
        $query = $this->consultaBaseLeidas($idUsuario)
            ->orderByDesc('nu.leido_en')
            ->orderByDesc('n.created_at')
            ->select($this->camposMensaje());

        if ($modulo !== null && trim($modulo) !== '') {
            $modulo = $this->normalizarModulo($modulo);

            if (!$this->moduloValido($modulo)) {
                return [
                    'status' => 'invalid_module',
                    'message' => 'Modulo no valido',
                ];
            }

            $query->where('n.modulo', $modulo);
        }

        $paginador = $query->paginate($porPagina);

        return [
            'status' => 'success',
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
            'resumen' => [
                'pendientes' => $this->contarNoLeidas($idUsuario),
            ],
        ];
    }

    /**
     * Nivel 1 devuelve cantidad de notificaciones leidas por modulo
     */
    public function obtenerResumenModulosLeidos(int $idUsuario): array
    {
        $conteos = $this->consultaBaseLeidas($idUsuario)
            ->select('n.modulo', DB::raw('COUNT(*) as cantidad'))
            ->groupBy('n.modulo')
            ->pluck('cantidad', 'modulo');

        $data = collect($this->modulosBase())
            ->map(function (array $modulo) use ($conteos) {
                $cantidad = (int) ($conteos[$modulo['modulo']] ?? 0);

                return [
                    'modulo' => $modulo['modulo'],
                    'titulo' => $modulo['titulo'],
                    'cantidad' => $cantidad,
                ];
            })
            ->values()
            ->all();

        return [
            'status' => 'success',
            'data' => $data,
            'total' => collect($data)->sum('cantidad'),
            'resumen' => [
                'pendientes' => $this->contarNoLeidas($idUsuario),
            ],
        ];
    }

    /**
     * Nivel 2 devuelve grupos leidos o mensajes directos leidos de un modulo
     */
    public function obtenerSegundoNivelLeidasPorModulo(
        int $idUsuario,
        string $modulo,
        int $porPagina = 20
    ): array {
        $modulo = $this->normalizarModulo($modulo);

        if (!$this->moduloValido($modulo)) {
            return [
                'status' => 'invalid_module',
                'message' => 'Modulo no valido',
            ];
        }

        if ($modulo === self::MODULO_ADMINISTRACION) {
            $query = $this->consultaBaseLeidas($idUsuario)
                ->where('n.modulo', self::MODULO_ADMINISTRACION)
                ->orderByDesc('nu.leido_en')
                ->orderByDesc('n.created_at')
                ->select($this->camposMensaje());

            $response = $this->paginarMensajes($query, $porPagina);

            return [
                'status' => 'success',
                'modulo' => $modulo,
                'tipo_vista' => 'mensajes_directos',
                'data' => $response['data'],
                'meta' => $response['meta'],
                'resumen' => [
                    'pendientes' => $this->contarNoLeidas($idUsuario),
                ],
            ];
        }

        $grupos = $this->consultaBaseLeidas($idUsuario)
            ->where('n.modulo', $modulo)
            ->select(
                'n.contexto_referencia',
                'n.grupo_titulo',
                DB::raw('COUNT(*) as cantidad'),
                DB::raw('MAX(nu.leido_en) as ultimo_leido_en'),
                DB::raw('MAX(n.created_at) as ultimo_creado_en')
            )
            ->groupBy('n.contexto_referencia', 'n.grupo_titulo')
            ->orderByDesc('ultimo_leido_en')
            ->orderByDesc('ultimo_creado_en')
            ->get()
            ->map(function ($row) {
                return [
                    'contexto_referencia' => $row->contexto_referencia,
                    'titulo' => $row->grupo_titulo ?: 'Sin grupo',
                    'cantidad' => (int) $row->cantidad,
                    'ultimo_leido_en' => $row->ultimo_leido_en,
                ];
            })
            ->values()
            ->all();

        return [
            'status' => 'success',
            'modulo' => $modulo,
            'tipo_vista' => 'grupos',
            'data' => $grupos,
            'total' => collect($grupos)->sum('cantidad'),
            'resumen' => [
                'pendientes' => $this->contarNoLeidas($idUsuario),
            ],
        ];
    }

    /**
     * Nivel 3 devuelve mensajes individuales leidos de un grupo
     */
    public function obtenerMensajesLeidosPorGrupo(
        int $idUsuario,
        string $modulo,
        string $contextoReferencia,
        int $porPagina = 20
    ): array {
        $modulo = $this->normalizarModulo($modulo);
        $contextoReferencia = trim($contextoReferencia);

        if (!$this->moduloValido($modulo)) {
            return [
                'status' => 'invalid_module',
                'message' => 'Modulo no valido',
            ];
        }

        if ($contextoReferencia === '') {
            return [
                'status' => 'invalid_payload',
                'message' => 'Debe enviar una referencia de grupo',
            ];
        }

        $query = $this->consultaBaseLeidas($idUsuario)
            ->where('n.modulo', $modulo)
            ->where('n.contexto_referencia', $contextoReferencia)
            ->orderByDesc('nu.leido_en')
            ->orderByDesc('n.created_at')
            ->select($this->camposMensaje());

        $response = $this->paginarMensajes($query, $porPagina);

        return [
            'status' => 'success',
            'modulo' => $modulo,
            'contexto_referencia' => $contextoReferencia,
            'data' => $response['data'],
            'meta' => $response['meta'],
            'resumen' => [
                'pendientes' => $this->contarNoLeidas($idUsuario),
            ],
        ];
    }

    /**
     * Consulta base para traer solo notificaciones no leidas del usuario
     */
    private function consultaBaseNoLeidas(int $idUsuario)
    {
        return $this->consultaBaseUsuario($idUsuario)
            ->whereNull('nu.leido_en');
    }

    /**
     * Consulta base para traer solo notificaciones leidas del usuario
     */
    private function consultaBaseLeidas(int $idUsuario)
    {
        return $this->consultaBaseUsuario($idUsuario)
            ->whereNotNull('nu.leido_en');
    }

    /**
     * Consulta base de notificaciones del usuario
     */
    private function consultaBaseUsuario(int $idUsuario)
    {
        return DB::table('notificacion_usuario as nu')
            ->join('notificaciones as n', 'n.id_notificacion', '=', 'nu.id_notificacion')
            ->leftJoin('usuarios as actor', 'actor.id_usuario', '=', 'n.id_usuario_actor')
            ->where('nu.id_usuario', $idUsuario);
    }

    /**
     * Devuelve mensajes directos de administracion
     */
    private function obtenerMensajesAdministracionNoLeidos(int $idUsuario): array
    {
        return $this->consultaBaseNoLeidas($idUsuario)
            ->where('n.modulo', self::MODULO_ADMINISTRACION)
            ->orderByDesc('n.created_at')
            ->select($this->camposMensaje())
            ->get()
            ->map(fn ($row) => $this->formatearMensaje($row))
            ->values()
            ->all();
    }

    /**
     * Campos comunes para mensajes individuales
     */
    private function camposMensaje(): array
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
            'n.created_at',
            'nu.leido_en',
            'actor.nombre as actor_nombre',
            'actor.apellido as actor_apellido',
            'actor.correo as actor_correo',
        ];
    }

    /**
     * Formatea una notificacion individual para el frontend
     */
    private function formatearMensaje(object $row): array
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
            'created_at' => $row->created_at,
            'leido_en' => $row->leido_en,
            'actor' => $row->id_usuario_actor ? [
                'id_usuario' => (int) $row->id_usuario_actor,
                'nombre' => trim(($row->actor_nombre ?? '') . ' ' . ($row->actor_apellido ?? '')),
                'correo' => $row->actor_correo,
            ] : null,
        ];
    }

    /**
     * Obtiene una notificacion concreta del usuario con el mismo formato publico
     */
    private function obtenerNotificacionDelUsuario(int $idUsuario, int $idNotificacion): ?array
    {
        $row = $this->consultaBaseUsuario($idUsuario)
            ->where('n.id_notificacion', $idNotificacion)
            ->select($this->camposMensaje())
            ->first();

        return $row ? $this->formatearMensaje($row) : null;
    }

    /**
     * Pagina mensajes individuales manteniendo un contrato compacto para frontend
     */
    private function paginarMensajes($query, int $porPagina): array
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

    /**
     * Marca como leidos los registros encontrados
     */
    private function marcarPivotsComoLeidos(Collection $idsPivot): int
    {
        $ids = $idsPivot
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::table('notificacion_usuario')
            ->whereIn('id_notificacion_usuario', $ids->all())
            ->whereNull('leido_en')
            ->update([
                'leido_en' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Modulos disponibles para el primer nivel
     */
    private function modulosBase(): array
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
        ];
    }

    /**
     * Normaliza nombres de modulo enviados por el frontend
     */
    private function normalizarModulo(string $modulo): string
    {
        $modulo = strtolower(trim($modulo));

        return match ($modulo) {
            'proyecto', 'proyectos' => self::MODULO_PROYECTOS,
            'evento', 'eventos' => self::MODULO_EVENTOS,
            'admin', 'administracion' => self::MODULO_ADMINISTRACION,
            default => $modulo,
        };
    }

    /**
     * Valida que el modulo exista
     */
    private function moduloValido(string $modulo): bool
    {
        return in_array($modulo, [
            self::MODULO_PROYECTOS,
            self::MODULO_EVENTOS,
            self::MODULO_ADMINISTRACION,
        ], true);
    }


    /**
     * Genera notificaciones personales de hoy y manana antes de mostrar
     */
    private function generarNotificacionesEventosProximos(int $idUsuario): void
    {
        $this->eventosNotificacionGuardadoService->generarNotificacionesGeneralesProximas($idUsuario);
    }
}
