<?php

namespace App\Services\api\Proyecto;

use App\Services\api\GithubRepositorySyncService;
use App\Services\api\ProyectoNotificacionGuardadoService;
use App\Services\api\TecnologiaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProyectoEnlaceService
{
    private const TIPO_REPOSITORIO = 'repositorio';

    private const TIPO_DEMO = 'demo';

    private const TIPO_VIDEO = 'video';

    public function __construct(
        private readonly GithubRepositorySyncService $githubRepositorySyncService,
        private readonly TecnologiaService $tecnologiaService,
        private readonly ProyectoNotificacionGuardadoService $proyectoNotificacionGuardadoService,
        private readonly ProyectoConsultaService $proyectoConsultaService,
        private readonly ProyectoSerializer $proyectoSerializer,
    ) {}

    public static function validationRules(): array
    {
        return [
            'url_repositorios' => 'sometimes|array',
            'url_repositorios.*' => 'nullable|string|max:500',
            'url_demo' => 'nullable|string|max:500',
            'url_videos' => 'sometimes|array',
            'url_videos.*' => 'nullable|string|max:500',
        ];
    }

    public function update(int $userId, int $idProyecto, array $payload): array
    {
        $this->sync($userId, $idProyecto, $payload);

        if ($payload !== []) {
            $this->proyectoNotificacionGuardadoService->notificarEnlacesProyectoActualizados(
                idProyecto: $idProyecto,
                idUsuarioActor: $userId
            );
        }

        $project = $this->proyectoConsultaService->findForUser($userId, $idProyecto);

        return $this->proyectoSerializer->serialize($project);
    }

    public function sync(int $userId, int $idProyecto, array $payload): void
    {
        $this->syncLinkEvidences($idProyecto, $payload);
        $this->syncProjectRepositories($userId, $idProyecto, $payload);
        $this->syncProjectTechnologies($userId, $idProyecto, $payload);
    }

    private function syncLinkEvidences(int $idProyecto, array $payload): void
    {
        if (! array_key_exists('url_demo', $payload) && ! array_key_exists('url_videos', $payload)) {
            return;
        }

        DB::table('proyecto_evidencias')
            ->where('id_proyecto', $idProyecto)
            ->whereIn('tipo', [self::TIPO_DEMO, self::TIPO_VIDEO])
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        $insert = [];
        $now = now();
        $demo = isset($payload['url_demo']) && is_string($payload['url_demo'])
            ? trim($payload['url_demo'])
            : '';

        if ($demo !== '') {
            $insert[] = $this->evidenceRow($idProyecto, 'Demo', self::TIPO_DEMO, $demo, 0, $now);
        }

        $videos = collect($payload['url_videos'] ?? [])
            ->filter(fn ($video) => is_string($video) && trim($video) !== '')
            ->values();

        foreach ($videos as $index => $url) {
            $insert[] = $this->evidenceRow($idProyecto, 'Video', self::TIPO_VIDEO, trim($url), $index, $now);
        }

        if ($insert !== []) {
            DB::table('proyecto_evidencias')->insert($insert);
        }
    }

    private function evidenceRow(int $idProyecto, string $titulo, string $tipo, string $url, int $orden, $now): array
    {
        return [
            'id_proyecto' => $idProyecto,
            'titulo' => $titulo,
            'descripcion' => null,
            'tipo' => $tipo,
            'url' => $url,
            'archivo_path' => null,
            'mime_type' => null,
            'tamanio_bytes' => null,
            'es_portada' => 'false',
            'es_visible' => 'true',
            'orden' => $orden,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function syncProjectRepositories(int $userId, int $idProyecto, array $payload): void
    {
        if (! array_key_exists('url_repositorios', $payload)) {
            return;
        }

        DB::table('proyecto_evidencias')
            ->where('id_proyecto', $idProyecto)
            ->where('tipo', self::TIPO_REPOSITORIO)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        $githubUrls = collect($payload['url_repositorios'] ?? [])
            ->filter(fn ($url) => is_string($url) && str_contains(strtolower($url), 'github.com/'))
            ->values()
            ->all();

        if ($githubUrls === []) {
            return;
        }

        $result = $this->githubRepositorySyncService->syncProjectRepoUrlsForUsuario(
            $userId,
            $idProyecto,
            $githubUrls,
        );

        if (($result['status'] ?? 'error') !== 'success') {
            abort($result['http_status'] ?? 422, $result['message'] ?? 'No se pudieron validar los repositorios.');
        }
    }

    private function syncProjectTechnologies(int $userId, int $idProyecto, array $payload): void
    {
        $hasTechnologies = array_key_exists('etiquetas', $payload)
            || array_key_exists('tecnologias', $payload)
            || array_key_exists('url_repositorios', $payload);

        if (! $hasTechnologies) {
            return;
        }

        $manual = collect($payload['tecnologias'] ?? $payload['etiquetas'] ?? []);
        $detected = $manual->isEmpty()
            ? $this->detectTechnologiesFromRepositories($userId, $payload['url_repositorios'] ?? [])
            : [];

        $names = $manual
            ->merge($detected)
            ->map(fn ($value) => is_string($value) ? trim($value) : '')
            ->filter()
            ->unique(fn ($value) => Str::lower($value))
            ->values();

        if ($names->isEmpty()) {
            DB::table('uso_tecnologias')
                ->where('id_proyecto', $idProyecto)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            return;
        }

        $ids = [];
        foreach ($names as $index => $name) {
            $technology = ($this->tecnologiaService->agregarBasicaPorNombre($name))['tecnologia'] ?? null;
            if (! $technology?->id_tecnologia) {
                continue;
            }

            $idTechnology = (int) $technology->id_tecnologia;
            $ids[] = $idTechnology;

            DB::table('uso_tecnologias')->updateOrInsert(
                ['id_proyecto' => $idProyecto, 'id_tecnologia' => $idTechnology],
                [
                    'version_usada' => null,
                    'porcentaje_uso' => null,
                    'es_principal' => $index < 3 ? 'true' : 'false',
                    'es_visible' => 'true',
                    'deleted_at' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        DB::table('uso_tecnologias')
            ->where('id_proyecto', $idProyecto)
            ->whereNull('deleted_at')
            ->when($ids !== [], fn ($query) => $query->whereNotIn('id_tecnologia', $ids))
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    private function detectTechnologiesFromRepositories(int $userId, array $repoUrls): array
    {
        $technologies = [];

        foreach ($repoUrls as $repoUrl) {
            if (! is_string($repoUrl) || trim($repoUrl) === '') {
                continue;
            }

            $result = $this->githubRepositorySyncService->fetchRepoLanguagesForUsuario($userId, $repoUrl);
            if (($result['status'] ?? null) === 'success' && is_array($result['languages'] ?? null)) {
                $technologies = array_merge($technologies, $result['languages']);
            }
        }

        return $technologies;
    }
}
