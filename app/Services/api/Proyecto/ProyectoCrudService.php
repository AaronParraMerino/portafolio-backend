<?php

namespace App\Services\api\Proyecto;

use App\Services\api\ContenidoAutoTraduccionService;
use App\Services\api\GithubRepositorySyncService;
use App\Services\api\ProyectoNotificacionGuardadoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProyectoCrudService
{
    public function __construct(
        private readonly GithubRepositorySyncService $githubRepositorySyncService,
        private readonly ProyectoNotificacionGuardadoService $proyectoNotificacionGuardadoService,
        private readonly ProyectoConsultaService $proyectoConsultaService,
        private readonly ProyectoSerializer $proyectoSerializer,
        private readonly ProyectoEnlaceService $proyectoEnlaceService,
        private readonly ContenidoAutoTraduccionService $autoTraduccionService,
    ) {}

    public static function validationRules(bool $partial = false): array
    {
        return [
            'titulo' => [$partial ? 'sometimes' : 'required', 'string', 'max:200'],
            'descripcion' => 'nullable|string|max:600',
            'estado' => [
                $partial ? 'sometimes' : 'required',
                'string',
                'max:30',
                'not_in:sin_especificar',
                'in:borrador,publicado,archivado,en_desarrollo,desarrollo,pausado,terminado,mantenimiento,versionado,cancelado',
            ],
            'estado_publicacion' => 'nullable|string|max:30|in:borrador,publicado,archivado',
            'estado_desarrollo' => 'nullable|string|max:30|in:sin_especificar,en_desarrollo,desarrollo,pausado,terminado,mantenimiento,versionado,cancelado',
            'tipo' => 'nullable|string|max:80',
            'desarrollado_para' => 'nullable|string|max:80',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'es_publico' => 'nullable|boolean',
            'rol' => 'nullable|string|max:100',
            'descripcion_aporte' => 'nullable|string|max:600',
            'url_repositorios' => 'nullable|array',
            'url_repositorios.*' => 'nullable|string|max:500',
            'url_demo' => 'nullable|string|max:500',
            'url_videos' => 'nullable|array',
            'url_videos.*' => 'nullable|string|max:500',
            'etiquetas' => 'nullable|array',
            'etiquetas.*' => 'nullable|string|max:100',
            'tecnologias' => 'nullable|array',
            'tecnologias.*' => 'nullable|string|max:100',
        ];
    }

    public function create(int $userId, array $payload): array
    {
        $existing = $this->githubRepositorySyncService->findExistingProjectForJoinableRepoUrls(
            $userId,
            $payload['url_repositorios'] ?? [],
        );

        if (($existing['status'] ?? null) === 'repo_requires_validation') {
            return ['body' => $existing, 'status' => $existing['http_status'] ?? 403];
        }

        if (($existing['status'] ?? null) === 'success') {
            $idProyecto = (int) $existing['id_proyecto'];
            $this->githubRepositorySyncService->linkUsuarioToExistingProjectByRepo($userId, $idProyecto, [
                'rol' => $payload['rol'] ?? null,
                'descripcion_aporte' => $payload['descripcion_aporte'] ?? null,
            ]);
            $this->proyectoNotificacionGuardadoService->notificarNuevoParticipante(
                idProyecto: $idProyecto,
                idUsuarioNuevo: $userId,
                idUsuarioActor: $userId
            );

            $this->traducirParticipacion($userId, $idProyecto, true);

            return [
                'body' => [
                    'message' => 'Este repositorio ya tenia un proyecto vinculado; se agrego tu participacion al proyecto existente.',
                    'data' => $this->serializedProject($userId, $idProyecto),
                    'linked_existing_project' => true,
                ],
                'status' => 200,
            ];
        }

        $idProyecto = DB::transaction(function () use ($payload, $userId) {
            $now = now();
            $publicationStatus = $this->mapPublicationStatus($payload['estado_publicacion'] ?? $payload['estado']);
            $developmentStatus = $this->mapDevelopmentStatus($payload['estado_desarrollo'] ?? $payload['estado']);

            $idProyecto = DB::table('proyectos')->insertGetId([
                'titulo' => $payload['titulo'],
                'descripcion' => $payload['descripcion'] ?? null,
                'plataforma_objetivo' => $this->mapPlatform($payload['desarrollado_para'] ?? null),
                'categoria_proyecto' => $this->mapCategory($payload['tipo'] ?? null),
                'estado_publicacion' => $publicationStatus,
                'estado_desarrollo' => $developmentStatus,
                'fecha_inicio' => $payload['fecha_inicio'] ?? null,
                'fecha_fin' => $payload['fecha_fin'] ?? null,
                'origen' => 'manual',
                'es_destacado' => 'false',
                'orden' => 0,
                'publicado_at' => $publicationStatus === 'publicado' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id_proyecto');

            DB::table('participaciones')->insert([
                'id_usuario' => $userId,
                'id_proyecto' => $idProyecto,
                'rol' => $payload['rol'] ?? null,
                'descripcion_aporte' => $payload['descripcion_aporte'] ?? null,
                'es_propietario' => 'true',
                'visibilidad' => ($payload['es_publico'] ?? true) ? 'publico' : 'privado',
                'estado_participacion' => 'activo',
                'fecha_inicio' => $payload['fecha_inicio'] ?? null,
                'fecha_fin' => $payload['fecha_fin'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('proyecto_configuraciones')->insertOrIgnore([
                'id_proyecto' => $idProyecto,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return (int) $idProyecto;
        });

        $this->proyectoEnlaceService->sync($userId, $idProyecto, $payload);
        $this->traducirProyecto($userId, $idProyecto, [
            'titulo' => $payload['titulo'],
            'descripcion' => $payload['descripcion'] ?? null,
        ]);
        $this->traducirParticipacion($userId, $idProyecto, true);

        return ['body' => ['data' => $this->serializedProject($userId, $idProyecto)], 'status' => 201];
    }

    public function update(int $userId, int $idProyecto, array $project, array $payload): array
    {
        $tituloAnterior = trim((string) ($project['titulo'] ?? ''));
        $tituloNuevo = array_key_exists('titulo', $payload)
            ? trim((string) $payload['titulo'])
            : $tituloAnterior;
        $tituloCambiado = $tituloNuevo !== '' && $tituloAnterior !== '' && $tituloNuevo !== $tituloAnterior;

        DB::transaction(function () use ($payload, $idProyecto, $userId, $project) {
            $projectUpdate = $this->projectUpdate($payload, $project);
            if ($projectUpdate !== []) {
                $projectUpdate['updated_at'] = now();
                DB::table('proyectos')->where('id_proyecto', $idProyecto)->update($projectUpdate);
            }

            $participationUpdate = $this->participationUpdate($payload);
            if ($participationUpdate !== []) {
                $participationUpdate['updated_at'] = now();
                DB::table('participaciones')
                    ->where('id_proyecto', $idProyecto)
                    ->where('id_usuario', $userId)
                    ->whereNull('deleted_at')
                    ->update($participationUpdate);
            }
        });

        $camposProyecto = array_intersect_key($payload, array_flip(['titulo', 'descripcion']));
        if ($camposProyecto !== []) {
            $this->traducirProyecto($userId, $idProyecto, $camposProyecto);
        }

        if (array_intersect_key($payload, array_flip(['rol', 'descripcion_aporte'])) !== []) {
            $this->traducirParticipacion($userId, $idProyecto, false, $payload);
        }

        $this->proyectoEnlaceService->sync($userId, $idProyecto, $payload);
        $payloadActualizacion = $tituloCambiado
            ? array_diff_key($payload, ['titulo' => true])
            : $payload;

        if ($tituloCambiado) {
            $this->proyectoNotificacionGuardadoService->notificarProyectoRenombrado(
                idProyecto: $idProyecto,
                idUsuarioActor: $userId,
                tituloAnterior: $tituloAnterior,
                tituloNuevo: $tituloNuevo
            );
        }
        $this->proyectoNotificacionGuardadoService->notificarProyectoActualizado(
            idProyecto: $idProyecto,
            idUsuarioActor: $userId,
            payload: $payloadActualizacion
        );

        return $this->serializedProject($userId, $idProyecto);
    }

    private function traducirProyecto(int $userId, int $idProyecto, array $campos): void
    {
        $this->autoTraduccionService->traducirEntidad(
            'proyecto',
            $idProyecto,
            $userId,
            array_intersect_key($campos, array_flip(['titulo', 'descripcion']))
        );
    }

    private function traducirParticipacion(
        int $userId,
        int $idProyecto,
        bool $todosLosCampos = false,
        array $payload = []
    ): void {
        $participacion = DB::table('participaciones')
            ->where('id_usuario', $userId)
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->first();

        if (! $participacion) {
            return;
        }

        $campos = [
            'rol' => $participacion->rol,
            'descripcion_aporte' => $participacion->descripcion_aporte,
        ];

        if (! $todosLosCampos) {
            $campos = array_intersect_key($campos, array_intersect_key(
                $payload,
                array_flip(['rol', 'descripcion_aporte'])
            ));
        }

        $this->autoTraduccionService->traducirEntidad(
            'participacion',
            (int) $participacion->id_participacion,
            $userId,
            $campos
        );
    }

    private function serializedProject(int $userId, int $idProyecto): array
    {
        return $this->proyectoSerializer->serialize(
            $this->proyectoConsultaService->findForUser($userId, $idProyecto)
        );
    }

    private function projectUpdate(array $payload, array $project): array
    {
        $update = [];
        foreach (['titulo', 'descripcion', 'fecha_inicio', 'fecha_fin'] as $field) {
            if (array_key_exists($field, $payload)) {
                $update[$field] = $payload[$field];
            }
        }

        if (array_key_exists('desarrollado_para', $payload)) {
            $update['plataforma_objetivo'] = $this->mapPlatform($payload['desarrollado_para']);
        }
        if (array_key_exists('tipo', $payload)) {
            $update['categoria_proyecto'] = $this->mapCategory($payload['tipo']);
        }
        if (array_key_exists('estado_publicacion', $payload) || array_key_exists('estado', $payload)) {
            $update['estado_publicacion'] = $this->mapPublicationStatus($payload['estado_publicacion'] ?? $payload['estado']);
            $update['publicado_at'] = $update['estado_publicacion'] === 'publicado' && empty($project['publicado_at'])
                ? now()
                : ($update['estado_publicacion'] !== 'publicado' ? null : $project['publicado_at']);
        }
        if (array_key_exists('estado_desarrollo', $payload) || array_key_exists('estado', $payload)) {
            $update['estado_desarrollo'] = $this->mapDevelopmentStatus($payload['estado_desarrollo'] ?? $payload['estado']);
        }

        return $update;
    }

    private function participationUpdate(array $payload): array
    {
        $update = [];
        foreach (['rol', 'descripcion_aporte', 'fecha_inicio', 'fecha_fin'] as $field) {
            if (array_key_exists($field, $payload)) {
                $update[$field] = $payload[$field];
            }
        }
        if (array_key_exists('es_publico', $payload)) {
            $update['visibilidad'] = $payload['es_publico'] ? 'publico' : 'privado';
        }

        return $update;
    }

    private function mapPlatform(?string $value): string
    {
        $map = [
            'web' => 'web', 'movil' => 'movil', 'mobile' => 'movil', 'tablet' => 'movil',
            'escritorio' => 'escritorio', 'desktop' => 'escritorio', 'web_movil' => 'web_movil',
            'tablet_web' => 'web_movil', 'multiplataforma' => 'multiplataforma', 'api' => 'api_backend',
            'api_backend' => 'api_backend', 'servidor' => 'api_backend', 'datos_ml' => 'datos_ml',
            'data' => 'datos_ml', 'data_bi' => 'datos_ml', 'ia_ml' => 'datos_ml',
            'machine_learning' => 'datos_ml', 'terminal' => 'cli', 'cli' => 'cli', 'iot' => 'iot',
            'auto' => 'iot', 'reloj' => 'iot', 'televisor' => 'multiplataforma',
            'consola' => 'multiplataforma', 'kiosko' => 'multiplataforma', 'otro' => 'otro',
        ];

        return $map[Str::lower(trim((string) $value))] ?? 'sin_especificar';
    }

    private function mapCategory(?string $value): string
    {
        $map = [
            'sin_especificar' => 'sin_especificar', 'web' => 'portafolio', 'app_web' => 'productividad',
            'movil' => 'productividad', 'desktop' => 'productividad', 'videojuego' => 'videojuego',
            'api' => 'herramienta_desarrollo', 'microservicio' => 'herramienta_desarrollo',
            'ecommerce' => 'ecommerce', 'dashboard' => 'dashboard_bi', 'sistema_gestion' => 'gestion_empresarial',
            'saas' => 'productividad', 'ia_ml' => 'herramienta_desarrollo', 'data_bi' => 'dashboard_bi',
            'iot' => 'herramienta_desarrollo', 'automatizacion' => 'herramienta_desarrollo',
            'plugin' => 'herramienta_desarrollo', 'libreria' => 'herramienta_desarrollo',
            'bot' => 'herramienta_desarrollo', 'blockchain' => 'seguridad', 'ar_vr' => 'entretenimiento',
            'educativo' => 'educativo', 'investigacion' => 'educativo', 'otro' => 'otro',
            'portafolio' => 'portafolio', 'financiero' => 'financiero', 'marketplace' => 'marketplace',
            'salud' => 'salud', 'administrativo' => 'administrativo', 'red_social' => 'red_social',
            'dashboard_bi' => 'dashboard_bi', 'gestion_empresarial' => 'gestion_empresarial',
            'productividad' => 'productividad', 'seguridad' => 'seguridad', 'entretenimiento' => 'entretenimiento',
            'herramienta_desarrollo' => 'herramienta_desarrollo',
        ];

        return $map[Str::lower(trim((string) $value))] ?? 'sin_especificar';
    }

    private function mapPublicationStatus(?string $value): string
    {
        return match (Str::lower(trim((string) $value))) {
            'publicado' => 'publicado',
            'archivado' => 'archivado',
            default => 'borrador',
        };
    }

    private function mapDevelopmentStatus(?string $value): string
    {
        return match (Str::lower(trim((string) $value))) {
            'desarrollo', 'en_desarrollo' => 'en_desarrollo',
            'terminado' => 'terminado',
            'mantenimiento' => 'mantenimiento',
            'versionado' => 'versionado',
            'pausado' => 'pausado',
            'cancelado' => 'cancelado',
            default => 'sin_especificar',
        };
    }
}
