<?php

namespace App\Services\api;

use App\Models\Notificacion;
use App\Models\NotificacionUsuario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProyectoNotificacionGuardadoService
{
    /**
     * Notifica a propietarios que un usuario se unió a un proyecto existente
     */
    public function notificarNuevoParticipante(
        int $idProyecto,
        int $idUsuarioNuevo,
        ?int $idUsuarioActor = null
    ): array {
        $proyecto = $this->obtenerProyecto($idProyecto);

        if (!$proyecto) {
            return $this->sinAccion('Proyecto no encontrado');
        }

        $propietarios = $this->obtenerPropietariosActivos($idProyecto, [$idUsuarioNuevo]);

        if ($propietarios->isEmpty()) {
            return $this->sinAccion('No hay propietarios para notificar');
        }

        return $this->crearParaUsuarios($propietarios, [
            'id_usuario_actor' => $idUsuarioActor ?? $idUsuarioNuevo,
            'tipo' => 'project_participant_added',
            'mensaje' => 'Un usuario se unió al proyecto "' . $proyecto->titulo . '".',
            'contexto_referencia' => 'proyecto_' . $idProyecto,
            'grupo_titulo' => $proyecto->titulo,
        ]);
    }

    /**
     * Notifica cuando un participante fue removido de un proyecto
     */
    public function notificarParticipanteRemovido(
        int $idProyecto,
        int $idUsuarioRemovido,
        int $idUsuarioActor
    ): array {
        $proyecto = $this->obtenerProyectoIncluyendoEliminados($idProyecto);

        if (!$proyecto) {
            return $this->sinAccion('Proyecto no encontrado');
        }

        $resultados = [];

        // Siempre se notifica al afectado.
        $resultados[] = $this->crearParaUsuarios(collect([$idUsuarioRemovido]), [
            'id_usuario_actor' => $idUsuarioActor,
            'tipo' => 'project_participant_removed',
            'mensaje' => 'Tu participación en el proyecto "' . $proyecto->titulo . '" fue removida.',
            'contexto_referencia' => 'proyecto_' . $idProyecto,
            'grupo_titulo' => $proyecto->titulo,
        ]);

        /*
         * Si el proyecto tiene 10 miembros o menos, también se avisa a los demás miembros,
         * excepto al usuario que hizo la acción y al usuario afectado.
         */
        if ($this->proyectoTieneGrupoPequeno($idProyecto)) {
            $participantes = $this->obtenerParticipantesActivos($idProyecto, [
                $idUsuarioActor,
                $idUsuarioRemovido,
            ]);

            if ($participantes->isNotEmpty()) {
                $resultados[] = $this->crearParaUsuarios($participantes, [
                    'id_usuario_actor' => $idUsuarioActor,
                    'tipo' => 'project_participant_removed_group',
                    'mensaje' => 'Un participante fue removido del proyecto "' . $proyecto->titulo . '".',
                    'contexto_referencia' => 'proyecto_' . $idProyecto,
                    'grupo_titulo' => $proyecto->titulo,
                ]);
            }
        }

        return $this->combinarResultados($resultados);
    }

    /**
     * Notifica cuando un participante se desvinculó de un proyecto
     */
    public function notificarParticipanteDesvinculado(
        int $idProyecto,
        int $idUsuarioDesvinculado
    ): array {
        $proyecto = $this->obtenerProyecto($idProyecto);

        if (!$proyecto) {
            return $this->sinAccion('Proyecto no encontrado');
        }

        if ($this->proyectoTieneGrupoPequeno($idProyecto)) {
            $destinatarios = $this->obtenerParticipantesActivos($idProyecto, [
                $idUsuarioDesvinculado,
            ]);
        } else {
            $destinatarios = $this->obtenerPropietariosActivos($idProyecto, [
                $idUsuarioDesvinculado,
            ]);
        }

        if ($destinatarios->isEmpty()) {
            return $this->sinAccion('No hay usuarios para notificar');
        }

        $nombreUsuario = $this->obtenerNombreUsuario($idUsuarioDesvinculado);

        return $this->crearParaUsuarios($destinatarios, [
            'id_usuario_actor' => $idUsuarioDesvinculado,
            'tipo' => 'project_participant_left',
            'mensaje' => $nombreUsuario . ' se desvinculó del proyecto "' . $proyecto->titulo . '".',
            'contexto_referencia' => 'proyecto_' . $idProyecto,
            'grupo_titulo' => $proyecto->titulo,
        ]);
    }

    /**
     * Notifica al usuario que su participación fue validada
     */
    public function notificarParticipacionValidada(
        int $idProyecto,
        int $idUsuarioValidado
    ): array {
        $proyecto = $this->obtenerProyecto($idProyecto);

        if (!$proyecto) {
            return $this->sinAccion('Proyecto no encontrado');
        }

        return $this->crearParaUsuarios(collect([$idUsuarioValidado]), [
            'id_usuario_actor' => null,
            'tipo' => 'project_participation_validated',
            'mensaje' => 'Se confirmó tu participación en el proyecto "' . $proyecto->titulo . '".',
            'contexto_referencia' => 'proyecto_' . $idProyecto,
            'grupo_titulo' => $proyecto->titulo,
        ]);
    }

    /**
     * Notifica al usuario que su participación no pudo validarse
     */
    public function notificarParticipacionNoValidada(
        int $idProyecto,
        int $idUsuarioDestino
    ): array {
        $proyecto = $this->obtenerProyecto($idProyecto);

        if (!$proyecto) {
            return $this->sinAccion('Proyecto no encontrado');
        }

        return $this->crearParaUsuarios(collect([$idUsuarioDestino]), [
            'id_usuario_actor' => null,
            'tipo' => 'project_participation_not_validated',
            'mensaje' => 'No se pudo confirmar tu participación en el proyecto "' . $proyecto->titulo . '".',
            'contexto_referencia' => 'proyecto_' . $idProyecto,
            'grupo_titulo' => $proyecto->titulo,
        ]);
    }

    /**
     * Notifica una desvinculacion automatica por perdida confirmada de acceso.
     */
    public function notificarDesvinculacionAutomaticaPorPerdidaValidacion(
        int $idProyecto,
        int $idUsuarioDestino
    ): array {
        $proyecto = $this->obtenerProyecto($idProyecto);

        if (!$proyecto) {
            return $this->sinAccion('Proyecto no encontrado');
        }

        return $this->crearParaUsuarios(collect([$idUsuarioDestino]), [
            'id_usuario_actor' => null,
            'tipo' => 'project_participation_access_revoked',
            'mensaje' => 'Tu participacion en el proyecto "' . $proyecto->titulo
                . '" fue desvinculada porque el proveedor confirmo que perdiste acceso al ultimo repositorio validado.',
            'contexto_referencia' => 'proyecto_' . $idProyecto,
            'grupo_titulo' => $proyecto->titulo,
        ]);
    }

    /**
     * Notifica a participantes cuando otro usuario actualizó un proyecto compartido
     */
    public function notificarProyectoActualizado(
        int $idProyecto,
        int $idUsuarioActor,
        array $payload
    ): array {
        if (!$this->payloadTieneCambiosNotificables($payload)) {
            return $this->sinAccion('No hay cambios notificables');
        }

        $proyecto = $this->obtenerProyecto($idProyecto);

        if (!$proyecto) {
            return $this->sinAccion('Proyecto no encontrado');
        }

        $participantes = $this->obtenerParticipantesActivos($idProyecto, [$idUsuarioActor]);

        if ($participantes->isEmpty()) {
            return $this->sinAccion('No hay otros participantes para notificar');
        }

        $nombreUsuario = $this->obtenerNombreUsuario($idUsuarioActor);

        return $this->crearParaUsuarios($participantes, [
            'id_usuario_actor' => $idUsuarioActor,
            'tipo' => 'project_updated',
            'mensaje' => $nombreUsuario . ' actualizó el proyecto "' . $proyecto->titulo . '".',
            'contexto_referencia' => 'proyecto_' . $idProyecto,
            'grupo_titulo' => $proyecto->titulo,
        ]);
    }

    /**
     * Notifica a participantes cuando un proyecto fue eliminado
     */
    public function notificarProyectoEliminado(
        int $idProyecto,
        int $idUsuarioActor
    ): array {
        $proyecto = $this->obtenerProyectoIncluyendoEliminados($idProyecto);

        if (!$proyecto) {
            return $this->sinAccion('Proyecto no encontrado');
        }

        $participantes = $this->obtenerParticipantesActivos($idProyecto, [$idUsuarioActor]);

        if ($participantes->isEmpty()) {
            return $this->sinAccion('No hay otros participantes para notificar');
        }

        $nombreUsuario = $this->obtenerNombreUsuario($idUsuarioActor);

        return $this->crearParaUsuarios($participantes, [
            'id_usuario_actor' => $idUsuarioActor,
            'tipo' => 'project_deleted',
            'mensaje' => $nombreUsuario . ' eliminó el proyecto "' . $proyecto->titulo . '".',
            'contexto_referencia' => 'proyecto_' . $idProyecto,
            'grupo_titulo' => $proyecto->titulo,
        ]);
    }

    /**
     * Notifica a participantes cuando la configuración del proyecto cambia
     */
    public function notificarConfiguracionActualizada(
        int $idProyecto,
        int $idUsuarioActor
    ): array {
        $proyecto = $this->obtenerProyecto($idProyecto);

        if (!$proyecto) {
            return $this->sinAccion('Proyecto no encontrado');
        }

        $participantes = $this->obtenerParticipantesActivos($idProyecto, [$idUsuarioActor]);

        if ($participantes->isEmpty()) {
            return $this->sinAccion('No hay otros participantes para notificar');
        }

        $nombreUsuario = $this->obtenerNombreUsuario($idUsuarioActor);

        return $this->crearParaUsuarios($participantes, [
            'id_usuario_actor' => $idUsuarioActor,
            'tipo' => 'project_configuration_updated',
            'mensaje' => $nombreUsuario . ' modificó la configuración del proyecto "' . $proyecto->titulo . '".',
            'contexto_referencia' => 'proyecto_' . $idProyecto,
            'grupo_titulo' => $proyecto->titulo,
        ]);
    }


    /**
     * Notifica a participantes cuando se actualizan materiales del proyecto
     */
    public function notificarMaterialesProyectoActualizados(
        int $idProyecto,
        int $idUsuarioActor,
        string $tipoMaterial,
        string $accion
    ): array {
        $proyecto = $this->obtenerProyecto($idProyecto);

        if (!$proyecto) {
            return $this->sinAccion('Proyecto no encontrado');
        }

        $participantes = $this->obtenerParticipantesActivos($idProyecto, [$idUsuarioActor]);

        if ($participantes->isEmpty()) {
            return $this->sinAccion('No hay otros participantes para notificar');
        }

        $nombreUsuario = $this->obtenerNombreUsuario($idUsuarioActor);

        $mensaje = match ($accion) {
            'agregado' => $nombreUsuario . ' agregó ' . $tipoMaterial . ' al proyecto "' . $proyecto->titulo . '".',
            'eliminado' => $nombreUsuario . ' eliminó ' . $tipoMaterial . ' del proyecto "' . $proyecto->titulo . '".',
            default => $nombreUsuario . ' actualizó ' . $tipoMaterial . ' del proyecto "' . $proyecto->titulo . '".',
        };

        return $this->crearParaUsuarios($participantes, [
            'id_usuario_actor' => $idUsuarioActor,
            'tipo' => 'project_materials_updated',
            'mensaje' => $mensaje,
            'contexto_referencia' => 'proyecto_' . $idProyecto,
            'grupo_titulo' => $proyecto->titulo,
        ]);
    }


    
    /**
     * Notifica a participantes cuando se agrega un repositorio relevante
     */
    /*
    public function notificarRepositorioAgregado(
        int $idProyecto,
        int $idUsuarioActor,
        ?string $urlRepositorio = null
    ): array {
        $proyecto = $this->obtenerProyecto($idProyecto);

        if (!$proyecto) {
            return $this->sinAccion('Proyecto no encontrado');
        }

        $participantes = $this->obtenerParticipantesActivos($idProyecto, [$idUsuarioActor]);

        if ($participantes->isEmpty()) {
            return $this->sinAccion('No hay otros participantes para notificar');
        }

        $nombreUsuario = $this->obtenerNombreUsuario($idUsuarioActor);

        return $this->crearParaUsuarios($participantes, [
            'id_usuario_actor' => $idUsuarioActor,
            'tipo' => 'project_repository_added',
            'mensaje' => $nombreUsuario . ' agregó un repositorio al proyecto "' . $proyecto->titulo . '".',
            'contexto_referencia' => 'proyecto_' . $idProyecto,
            'grupo_titulo' => $proyecto->titulo,
        ]);
    }
    */

    /**
     * Notifica a participantes cuando se actualizan enlaces del proyecto
     */
    public function notificarEnlacesProyectoActualizados(
        int $idProyecto,
        int $idUsuarioActor
    ): array {
        $proyecto = $this->obtenerProyecto($idProyecto);

        if (!$proyecto) {
            return $this->sinAccion('Proyecto no encontrado');
        }

        $participantes = $this->obtenerParticipantesActivos($idProyecto, [$idUsuarioActor]);

        if ($participantes->isEmpty()) {
            return $this->sinAccion('No hay otros participantes para notificar');
        }

        $nombreUsuario = $this->obtenerNombreUsuario($idUsuarioActor);

        return $this->crearParaUsuarios($participantes, [
            'id_usuario_actor' => $idUsuarioActor,
            'tipo' => 'project_links_updated',
            'mensaje' => $nombreUsuario . ' actualizó los enlaces del proyecto "' . $proyecto->titulo . '".',
            'contexto_referencia' => 'proyecto_' . $idProyecto,
            'grupo_titulo' => $proyecto->titulo,
        ]);
    }

    /**
     * Guarda una notificación y la asigna a varios usuarios
     */
    private function crearParaUsuarios(Collection $usuarios, array $data): array
    {
        $idsUsuarios = $this->normalizarIdsUsuarios($usuarios);

        $validacion = $this->validarDatos($data, $idsUsuarios);

        if (!$validacion['status']) {
            return $validacion;
        }

        try {
            $resultado = DB::transaction(function () use ($data, $idsUsuarios) {
                $notificacion = Notificacion::create([
                    'id_usuario_actor' => $data['id_usuario_actor'] ?? null,
                    'modulo' => 'proyectos',
                    'contexto_tipo' => 'proyecto',
                    'contexto_referencia' => $data['contexto_referencia'] ?? null,
                    'grupo_titulo' => $data['grupo_titulo'] ?? null,
                    'tipo' => $data['tipo'],
                    'mensaje' => trim($data['mensaje']),
                ]);

                foreach ($idsUsuarios as $idUsuario) {
                    NotificacionUsuario::create([
                        'id_notificacion' => $notificacion->id_notificacion,
                        'id_usuario' => $idUsuario,
                        'leido_en' => null,
                    ]);
                }

                return [
                    'notificacion' => $notificacion,
                    'destinatarios' => $idsUsuarios,
                ];
            });

            return [
                'status' => true,
                'message' => 'Notificación de proyecto guardada correctamente',
                'notificacion' => $resultado['notificacion'],
                'creadas' => [$resultado['notificacion']],
                'destinatarios' => $resultado['destinatarios'],
                'errores' => [],
            ];
        } catch (QueryException $e) {
            return [
                'status' => false,
                'message' => 'Error al guardar la notificación de proyecto',
                'creadas' => [],
                'errores' => [
                    [
                        'message' => $e->getMessage(),
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Error al guardar la notificación de proyecto',
                'creadas' => [],
                'errores' => [
                    [
                        'message' => $e->getMessage(),
                    ],
                ],
            ];
        }
    }

    /**
     * Valida los datos básicos de una notificación
     */
    private function validarDatos(array $data, Collection $idsUsuarios): array
    {
        if ($idsUsuarios->isEmpty()) {
            return [
                'status' => false,
                'message' => 'Debe existir al menos un usuario destino',
            ];
        }

        foreach (['tipo', 'mensaje'] as $campo) {
            if (!isset($data[$campo]) || trim((string) $data[$campo]) === '') {
                return [
                    'status' => false,
                    'message' => 'El campo ' . $campo . ' es obligatorio',
                ];
            }
        }

        if (isset($data['grupo_titulo']) && mb_strlen(trim($data['grupo_titulo'])) > 150) {
            return [
                'status' => false,
                'message' => 'El grupo_titulo no puede superar los 150 caracteres',
            ];
        }

        return ['status' => true];
    }

    /**
     * Normaliza una colección de usuarios u objetos a IDs únicos
     */
    private function normalizarIdsUsuarios(Collection $usuarios): Collection
    {
        return $usuarios
            ->map(function ($usuario) {
                return (int) ($usuario->id_usuario ?? $usuario);
            })
            ->filter(function (int $idUsuario) {
                return $idUsuario > 0;
            })
            ->unique()
            ->values();
    }

    /**
     * Obtiene un proyecto activo
     */
    private function obtenerProyecto(int $idProyecto): ?object
    {
        return DB::table('proyectos')
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Obtiene un proyecto aunque ya esté eliminado lógicamente
     */
    private function obtenerProyectoIncluyendoEliminados(int $idProyecto): ?object
    {
        return DB::table('proyectos')
            ->where('id_proyecto', $idProyecto)
            ->first();
    }

    /**
     * Obtiene participantes activos del proyecto
     */
    private function obtenerParticipantesActivos(int $idProyecto, array $excluirUsuarios = []): Collection
    {
        return DB::table('participaciones')
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->when(!empty($excluirUsuarios), function ($query) use ($excluirUsuarios) {
                $query->whereNotIn('id_usuario', $excluirUsuarios);
            })
            ->select('id_usuario')
            ->distinct()
            ->get();
    }

    /**
     * Obtiene propietarios activos del proyecto
     */
    /*private function obtenerPropietariosActivos(int $idProyecto, array $excluirUsuarios = []): Collection
    {
        return DB::table('participaciones as p')
            ->join('participacion_repositorios as pr', 'pr.id_participacion', '=', 'p.id_participacion')
            ->where('p.id_proyecto', $idProyecto)
            ->whereRaw('pr.es_propietario IS TRUE')
            ->whereRaw('pr.validado IS TRUE')
            ->whereNull('p.deleted_at')
            ->when(!empty($excluirUsuarios), function ($query) use ($excluirUsuarios) {
                $query->whereNotIn('p.id_usuario', $excluirUsuarios);
            })
            ->select('p.id_usuario')
            ->distinct()
            ->get();
    }*/
    /**
 * Obtiene propietarios activos del proyecto
 */
    private function obtenerPropietariosActivos(int $idProyecto, array $excluirUsuarios = []): Collection
    {
        return DB::table('participaciones as p')
            ->where('p.id_proyecto', $idProyecto)
            ->whereRaw('p.es_propietario IS TRUE')
            ->whereNull('p.deleted_at')
            ->when(!empty($excluirUsuarios), function ($query) use ($excluirUsuarios) {
                $query->whereNotIn('p.id_usuario', $excluirUsuarios);
            })
            ->select('p.id_usuario')
            ->distinct()
            ->get();
    }

    /**
     * Verifica si el proyecto tiene 10 miembros activos o menos
     */
    private function proyectoTieneGrupoPequeno(int $idProyecto): bool
    {
        $total = DB::table('participaciones')
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->distinct()
            ->count('id_usuario');

        return $total <= 10;
    }

    /**
     * Detecta campos de proyecto que sí ameritan notificación
     */
    private function payloadTieneCambiosNotificables(array $payload): bool
    {
        $campos = [
            'titulo',
            'descripcion',
            'estado',
            'estado_publicacion',
            'estado_desarrollo',
            'url_repositorios',
            'url_demo',
            'url_videos',
            'tecnologias',
            'etiquetas',
        ];

        foreach ($campos as $campo) {
            if (array_key_exists($campo, $payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Une varios resultados de creación en una sola respuesta
     */
    private function combinarResultados(array $resultados): array
    {
        $creadas = [];
        $errores = [];
        $destinatarios = [];

        foreach ($resultados as $resultado) {
            foreach (($resultado['creadas'] ?? []) as $notificacion) {
                if ($notificacion) {
                    $creadas[] = $notificacion;
                }
            }

            foreach (($resultado['errores'] ?? []) as $error) {
                $errores[] = $error;
            }

            foreach (($resultado['destinatarios'] ?? []) as $idUsuario) {
                $destinatarios[] = $idUsuario;
            }
        }

        return [
            'status' => empty($errores),
            'message' => empty($errores)
                ? 'Notificaciones de proyecto guardadas correctamente'
                : 'Algunas notificaciones de proyecto no pudieron guardarse',
            'creadas' => $creadas,
            'destinatarios' => array_values(array_unique($destinatarios)),
            'errores' => $errores,
        ];
    }


    /**
     * Obtiene solo el nombre de un usuario
     */
    private function obtenerNombreUsuario(int $idUsuario): string
    {
        $usuario = DB::table('usuarios')
            ->where('id_usuario', $idUsuario)
            ->select('nombre')
            ->first();

        if (!$usuario) {
            return 'Un participante';
        }

        return trim($usuario->nombre);
    }

    /**
     * Respuesta estándar cuando no corresponde guardar notificación
     */
    private function sinAccion(string $message): array
    {
        return [
            'status' => true,
            'message' => $message,
            'creadas' => [],
            'destinatarios' => [],
            'errores' => [],
        ];
    }
}
