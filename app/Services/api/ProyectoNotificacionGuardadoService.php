<?php

namespace App\Services\api;

use App\Models\Notificacion;
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
            'titulo' => 'Nuevo participante',
            'contenido' => 'Un usuario se unió al proyecto "' . $proyecto->titulo . '".',
            'referencia_id' => $idProyecto,
            'data' => [
                'id_proyecto' => $idProyecto,
                'titulo_proyecto' => $proyecto->titulo,
                'id_usuario_participante' => $idUsuarioNuevo,
            ],
            'event_key_base' => 'project_participant_added:project_' . $idProyecto . ':participant_' . $idUsuarioNuevo,
        ]);
    }

    /**
     * Notifica al usuario que fue removido de un proyecto
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

        return $this->crear([
            'id_usuario_destino' => $idUsuarioRemovido,
            'id_usuario_actor' => $idUsuarioActor,
            'tipo' => 'project_participant_removed',
            'titulo' => 'Fuiste removido',
            'contenido' => 'Tu participación en el proyecto "' . $proyecto->titulo . '" fue removida.',
            'referencia_id' => $idProyecto,
            'data' => [
                'id_proyecto' => $idProyecto,
                'titulo_proyecto' => $proyecto->titulo,
            ],
            'event_key' => 'project_participant_removed:project_' . $idProyecto . ':user_' . $idUsuarioRemovido,
        ]);
    }

    /**
     * Notifica a propietarios que un participante se desvinculó
     */
    public function notificarParticipanteDesvinculado(
        int $idProyecto,
        int $idUsuarioDesvinculado
    ): array {
        $proyecto = $this->obtenerProyecto($idProyecto);

        if (!$proyecto) {
            return $this->sinAccion('Proyecto no encontrado');
        }

        $propietarios = $this->obtenerPropietariosActivos($idProyecto, [$idUsuarioDesvinculado]);

        if ($propietarios->isEmpty()) {
            return $this->sinAccion('No hay propietarios para notificar');
        }

        return $this->crearParaUsuarios($propietarios, [
            'id_usuario_actor' => $idUsuarioDesvinculado,
            'tipo' => 'project_participant_left',
            'titulo' => 'Participante salió',
            'contenido' => 'Un participante se desvinculó del proyecto "' . $proyecto->titulo . '".',
            'referencia_id' => $idProyecto,
            'data' => [
                'id_proyecto' => $idProyecto,
                'titulo_proyecto' => $proyecto->titulo,
                'id_usuario_participante' => $idUsuarioDesvinculado,
            ],
            'event_key_base' => 'project_participant_left:project_' . $idProyecto . ':participant_' . $idUsuarioDesvinculado,
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

        return $this->crear([
            'id_usuario_destino' => $idUsuarioValidado,
            'id_usuario_actor' => null,
            'tipo' => 'project_participation_validated',
            'titulo' => 'Participación validada',
            'contenido' => 'Se confirmó tu participación en el proyecto "' . $proyecto->titulo . '".',
            'referencia_id' => $idProyecto,
            'data' => [
                'id_proyecto' => $idProyecto,
                'titulo_proyecto' => $proyecto->titulo,
            ],
            'event_key' => 'project_participation_validated:project_' . $idProyecto . ':user_' . $idUsuarioValidado,
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

        return $this->crear([
            'id_usuario_destino' => $idUsuarioDestino,
            'id_usuario_actor' => null,
            'tipo' => 'project_participation_not_validated',
            'titulo' => 'No se pudo validar',
            'contenido' => 'No se pudo confirmar tu participación en el proyecto "' . $proyecto->titulo . '".',
            'referencia_id' => $idProyecto,
            'data' => [
                'id_proyecto' => $idProyecto,
                'titulo_proyecto' => $proyecto->titulo,
            ],
            'event_key' => 'project_participation_not_validated:project_' . $idProyecto . ':user_' . $idUsuarioDestino,
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

        return $this->crearParaUsuarios($participantes, [
            'id_usuario_actor' => $idUsuarioActor,
            'tipo' => 'project_updated',
            'titulo' => 'Proyecto actualizado',
            'contenido' => 'Se actualizó el proyecto "' . $proyecto->titulo . '".',
            'referencia_id' => $idProyecto,
            'data' => [
                'id_proyecto' => $idProyecto,
                'titulo_proyecto' => $proyecto->titulo,
                'campos_actualizados' => array_keys($payload),
            ],
            'event_key_base' => 'project_updated:project_' . $idProyecto . ':update_' . now()->format('YmdHisv'),
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

        return $this->crearParaUsuarios($participantes, [
            'id_usuario_actor' => $idUsuarioActor,
            'tipo' => 'project_deleted',
            'titulo' => 'Proyecto eliminado',
            'contenido' => 'El proyecto "' . $proyecto->titulo . '" fue eliminado.',
            'referencia_id' => $idProyecto,
            'data' => [
                'id_proyecto' => $idProyecto,
                'titulo_proyecto' => $proyecto->titulo,
            ],
            'event_key_base' => 'project_deleted:project_' . $idProyecto,
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

        return $this->crearParaUsuarios($participantes, [
            'id_usuario_actor' => $idUsuarioActor,
            'tipo' => 'project_configuration_updated',
            'titulo' => 'Config. actualizada',
            'contenido' => 'Se modificó la configuración del proyecto "' . $proyecto->titulo . '".',
            'referencia_id' => $idProyecto,
            'data' => [
                'id_proyecto' => $idProyecto,
                'titulo_proyecto' => $proyecto->titulo,
            ],
            'event_key_base' => 'project_configuration_updated:project_' . $idProyecto . ':update_' . now()->format('YmdHisv'),
        ]);
    }

    /**
     * Notifica a participantes cuando se agrega un repositorio relevante
     */
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

        return $this->crearParaUsuarios($participantes, [
            'id_usuario_actor' => $idUsuarioActor,
            'tipo' => 'project_repository_added',
            'titulo' => 'Repositorio agregado',
            'contenido' => 'Se agregó un repositorio al proyecto "' . $proyecto->titulo . '".',
            'referencia_id' => $idProyecto,
            'data' => [
                'id_proyecto' => $idProyecto,
                'titulo_proyecto' => $proyecto->titulo,
                'url_repositorio' => $urlRepositorio,
            ],
            'event_key_base' => 'project_repository_added:project_' . $idProyecto . ':repo_' . md5((string) $urlRepositorio),
        ]);
    }

    /**
     * Guarda una notificación individual de proyecto
     */
    private function crear(array $data): array
    {
        $validacion = $this->validarDatos($data);

        if (!$validacion['status']) {
            return $validacion;
        }

        try {
            $notificacion = DB::transaction(function () use ($data) {
                $eventKey = $data['event_key'] ?? null;

                if ($eventKey) {
                    $existente = Notificacion::where('event_key', $eventKey)->first();

                    if ($existente) {
                        return $existente;
                    }
                }

                return Notificacion::create([
                    'id_usuario_destino' => $data['id_usuario_destino'],
                    'id_usuario_actor' => $data['id_usuario_actor'] ?? null,

                    'tipo' => $data['tipo'],
                    'modulo' => 'proyectos',

                    'titulo' => trim($data['titulo']),
                    'contenido' => isset($data['contenido']) ? trim($data['contenido']) : null,

                    'referencia_tipo' => 'project',
                    'referencia_id' => $data['referencia_id'] ?? null,

                    'data' => $data['data'] ?? null,
                    'event_key' => $eventKey,

                    'leida_en' => null,
                ]);
            });

            return [
                'status' => true,
                'message' => 'Notificación de proyecto guardada correctamente',
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
                'message' => 'Error al guardar la notificación de proyecto',
                'error' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => 'Error al guardar la notificación de proyecto',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Guarda una notificación para varios usuarios
     */
    private function crearParaUsuarios(Collection $usuarios, array $data): array
    {
        $creadas = [];
        $errores = [];

        foreach ($usuarios as $usuario) {
            $idUsuarioDestino = (int) ($usuario->id_usuario ?? $usuario);

            if ($idUsuarioDestino <= 0) {
                continue;
            }

            $eventKeyBase = $data['event_key_base'] ?? null;

            $resultado = $this->crear([
                'id_usuario_destino' => $idUsuarioDestino,
                'id_usuario_actor' => $data['id_usuario_actor'] ?? null,

                'tipo' => $data['tipo'],
                'titulo' => $data['titulo'],
                'contenido' => $data['contenido'] ?? null,

                'referencia_id' => $data['referencia_id'] ?? null,
                'data' => $data['data'] ?? null,

                'event_key' => $eventKeyBase
                    ? $eventKeyBase . ':user_' . $idUsuarioDestino
                    : null,
            ]);

            if ($resultado['status']) {
                $creadas[] = $resultado['notificacion'] ?? null;
            } else {
                $errores[] = [
                    'id_usuario_destino' => $idUsuarioDestino,
                    'message' => $resultado['message'] ?? 'Error al guardar notificación',
                ];
            }
        }

        return [
            'status' => empty($errores),
            'message' => empty($errores)
                ? 'Notificaciones de proyecto guardadas correctamente'
                : 'Algunas notificaciones de proyecto no pudieron guardarse',
            'creadas' => array_filter($creadas),
            'errores' => $errores,
        ];
    }

    /**
     * Valida los datos básicos de una notificación
     */
    private function validarDatos(array $data): array
    {
        foreach (['id_usuario_destino', 'tipo', 'titulo'] as $campo) {
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
    private function obtenerPropietariosActivos(int $idProyecto, array $excluirUsuarios = []): Collection
    {
        return DB::table('participaciones')
            ->where('id_proyecto', $idProyecto)
            ->whereRaw('es_propietario = TRUE')
            ->whereNull('deleted_at')
            ->when(!empty($excluirUsuarios), function ($query) use ($excluirUsuarios) {
                $query->whereNotIn('id_usuario', $excluirUsuarios);
            })
            ->select('id_usuario')
            ->distinct()
            ->get();
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
     * Detecta error por índice unique
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
     * Respuesta estándar cuando no corresponde guardar notificación
     */
    private function sinAccion(string $message): array
    {
        return [
            'status' => true,
            'message' => $message,
            'creadas' => [],
            'errores' => [],
        ];
    }
}