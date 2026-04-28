<?php

namespace App\Services\api;

use App\Models\Participacion;
use App\Models\Perfil;
use App\Models\Proyecto;
use App\Models\ProyectoEvidencia;
use App\Models\ProyectoGithub;
use App\Models\Tecnologia;
use App\Models\TipoProyecto;
use App\Models\UsoTecnologia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectService
{
    public function getByUserId(int $userId): array
    {
        return $this->ownedProjectsQuery($userId)
            ->get()
            ->map(fn (Proyecto $project) => $this->serializeProject($project, $userId))
            ->values()
            ->all();
    }

    public function findOwnedById(int $userId, int $projectId): ?Proyecto
    {
        return $this->ownedProjectsQuery($userId)
            ->where('id_proyecto', $projectId)
            ->first();
    }

    public function toApi(Proyecto $project, int $userId): array
    {
        return $this->serializeProject($project, $userId);
    }

    public function create(int $userId, array $data): array
    {
        return DB::transaction(function () use ($userId, $data) {
            $normalized = $this->normalizeData($data);

            $project = Proyecto::create($normalized['project']);

            Participacion::create([
                'id_usuario' => $userId,
                'id_proyecto' => $project->id_proyecto,
                'rol' => 'Propietario',
                'descripcion_aporte' => 'Proyecto creado por el usuario',
                'es_propietario' => DB::raw('true'),
                'visibilidad' => $normalized['visibility'],
                'estado_participacion' => 'activo',
                'fecha_inicio' => $normalized['project']['fecha_inicio'] ?? null,
                'fecha_fin' => $normalized['project']['fecha_fin'] ?? null,
            ]);

            $this->syncGithubData($project, $normalized['github']);
            $this->syncTechnologies($project, $normalized['tags']);

            return $this->serializeProject(
                $this->findOwnedById($userId, $project->id_proyecto),
                $userId
            );
        });
    }

    public function update(Proyecto $project, int $userId, array $data): array
    {
        return DB::transaction(function () use ($project, $userId, $data) {
            $normalized = $this->normalizeData($data, $project);

            if (! empty($normalized['project'])) {
                $project->update($normalized['project']);
            }

            if ($normalized['visibility'] !== null) {
                $participation = $project->participaciones
                    ->firstWhere('id_usuario', $userId);

                if ($participation) {
                    $participation->update(['visibilidad' => $normalized['visibility']]);
                }
            }

            if ($normalized['has_github_payload']) {
                $this->syncGithubData($project, $normalized['github']);
            }

            if ($normalized['has_tags_payload']) {
                $this->syncTechnologies($project, $normalized['tags']);
            }

            return $this->serializeProject(
                $this->findOwnedById($userId, $project->id_proyecto),
                $userId
            );
        });
    }

    public function updateVisibility(Proyecto $project, int $userId, bool $isPublic): array
    {
        $participation = $project->participaciones->firstWhere('id_usuario', $userId);

        if ($participation) {
            $participation->update([
                'visibilidad' => $isPublic ? 'publico' : 'privado',
            ]);
        }

        return [
            'id' => $project->id_proyecto,
            'es_publico' => $isPublic,
        ];
    }

    public function delete(Proyecto $project): void
    {
        DB::transaction(function () use ($project) {
            $evidences = ProyectoEvidencia::withTrashed()
                ->where('id_proyecto', $project->id_proyecto)
                ->get();

            foreach ($evidences as $evidence) {
                if ($evidence->archivo_path) {
                    Storage::disk('public')->delete($evidence->archivo_path);
                }
            }

            ProyectoEvidencia::withTrashed()
                ->where('id_proyecto', $project->id_proyecto)
                ->forceDelete();

            UsoTecnologia::withTrashed()
                ->where('id_proyecto', $project->id_proyecto)
                ->forceDelete();

            Participacion::withTrashed()
                ->where('id_proyecto', $project->id_proyecto)
                ->forceDelete();

            ProyectoGithub::query()
                ->where('id_proyecto', $project->id_proyecto)
                ->delete();

            $project->delete();
        });
    }

    public function uploadCoverImage(Proyecto $project, UploadedFile $file, bool $replaceExisting = false): array
    {
        return DB::transaction(function () use ($project, $file, $replaceExisting) {
            $currentCover = ProyectoEvidencia::query()
                ->where('id_proyecto', $project->id_proyecto)
                ->whereRaw('es_portada = true')
                ->latest('id_evidencia')
                ->first();

            if ($currentCover && ! $replaceExisting) {
                throw new \RuntimeException('El proyecto ya tiene una imagen de portada.');
            }

            if ($currentCover) {
                $this->removeCoverRecord($currentCover);
            }

            $ownerId = $this->resolveOwnerId($project);
            $path = $file->store('projects/'.$ownerId, 'public');

            $cover = ProyectoEvidencia::create([
                'id_proyecto' => $project->id_proyecto,
                'titulo' => 'Portada del proyecto',
                'tipo' => 'imagen',
                'archivo_path' => $path,
                'mime_type' => $file->getMimeType(),
                'tamanio_bytes' => $file->getSize(),
                'es_portada' => DB::raw('true'),
                'es_visible' => DB::raw('true'),
                'orden' => 0,
            ]);

            return [
                'message' => 'Imagen de portada actualizada correctamente',
                'data' => [
                    'id_evidencia' => $cover->id_evidencia,
                    'url' => Storage::disk('public')->url($path),
                ],
            ];
        });
    }

    public function deleteCoverImage(Proyecto $project): array
    {
        $currentCover = ProyectoEvidencia::query()
            ->where('id_proyecto', $project->id_proyecto)
            ->whereRaw('es_portada = true')
            ->latest('id_evidencia')
            ->first();

        if (! $currentCover) {
            return [
                'message' => 'El proyecto no tiene imagen de portada',
            ];
        }

        $this->removeCoverRecord($currentCover);

        return [
            'message' => 'Imagen eliminada correctamente',
        ];
    }

    public function updatePortfolioVisibility(int $userId, bool $isPublic): array
    {
        $profile = Perfil::firstOrCreate(
            ['usuario_id' => $userId],
            ['es_publico' => $isPublic]
        );

        if ($profile->es_publico !== $isPublic) {
            $profile->update(['es_publico' => $isPublic]);
        }

        return [
            'user_id' => $userId,
            'portfolio_publico' => $profile->es_publico,
        ];
    }

    private function ownedProjectsQuery(int $userId)
    {
        return Proyecto::query()
            ->whereHas('participaciones', function ($query) use ($userId) {
                $query->where('id_usuario', $userId);
            })
            ->with([
                'tipoProyecto',
                'github',
                'portada',
                'participaciones' => function ($query) use ($userId) {
                    $query->where('id_usuario', $userId);
                },
                'usosTecnologias' => function ($query) {
                    $query->with('tecnologia')->orderByDesc('es_principal')->orderBy('id_uso_tecnologia');
                },
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id_proyecto');
    }

    private function serializeProject(?Proyecto $project, int $userId): array
    {
        if (! $project) {
            return [];
        }

        $participation = $project->participaciones->firstWhere('id_usuario', $userId);
        $cover = $project->portada;

        return [
            'id' => $project->id_proyecto,
            'titulo' => $project->titulo,
            'descripcion' => $project->descripcion,
            'url_repositorio' => $project->github?->github_url,
            'url_demo' => $project->github?->github_homepage,
            'imagen_portada' => $cover?->archivo_path
                ? Storage::disk('public')->url($cover->archivo_path)
                : $cover?->url,
            'es_publico' => ($participation?->visibilidad ?? 'publico') === 'publico',
            'fecha_inicio' => $project->fecha_inicio?->format('Y-m-d'),
            'fecha_fin' => $project->fecha_fin?->format('Y-m-d'),
            'en_curso' => $project->estado_desarrollo === 'en_desarrollo' && $project->fecha_fin === null,
            'tipo' => $project->tipoProyecto?->nombre,
            'etiquetas' => $project->usosTecnologias
                ->map(fn (UsoTecnologia $use) => $use->tecnologia?->nombre)
                ->filter()
                ->values()
                ->all(),
            'estado' => $this->mapOutgoingState($project),
            'estado_publicacion' => $project->estado_publicacion,
            'estado_desarrollo' => $project->estado_desarrollo,
            'fecha_modificacion' => $project->updated_at?->toISOString(),
        ];
    }

    private function normalizeData(array $data, ?Proyecto $project = null): array
    {
        $projectData = [];
        $visibility = null;
        $hasGithubPayload = array_key_exists('url_repositorio', $data) || array_key_exists('url_demo', $data);
        $hasTagsPayload = array_key_exists('etiquetas', $data);

        if (array_key_exists('titulo', $data)) {
            $projectData['titulo'] = trim((string) $data['titulo']);
        }

        if (array_key_exists('descripcion', $data)) {
            $projectData['descripcion'] = $this->nullableString($data['descripcion']);
        }

        if (array_key_exists('fecha_inicio', $data)) {
            $projectData['fecha_inicio'] = $data['fecha_inicio'] ?: null;
        }

        if (array_key_exists('fecha_fin', $data)) {
            $projectData['fecha_fin'] = $data['fecha_fin'] ?: null;
        }

        if (array_key_exists('tipo', $data)) {
            $projectData['id_tipo_proyecto'] = $this->resolveTypeId($data['tipo']);
        }

        if (array_key_exists('es_publico', $data)) {
            $visibility = filter_var($data['es_publico'], FILTER_VALIDATE_BOOLEAN)
                ? 'publico'
                : 'privado';
        }

        [$estadoPublicacion, $estadoDesarrollo] = $this->resolveStates($data, $project);

        if ($estadoPublicacion !== null) {
            $projectData['estado_publicacion'] = $estadoPublicacion;
        }

        if ($estadoDesarrollo !== null) {
            $projectData['estado_desarrollo'] = $estadoDesarrollo;
        }

        if (
            array_key_exists('en_curso', $data)
            && filter_var($data['en_curso'], FILTER_VALIDATE_BOOLEAN)
            && ! array_key_exists('fecha_fin', $projectData)
        ) {
            $projectData['fecha_fin'] = null;
        }

        if (! $project) {
            $projectData['origen'] = empty($data['url_repositorio']) ? 'manual' : 'github';
            // es_destacado has DB default false — omit to avoid PG boolean cast error
            $projectData['orden'] = 0;
        } elseif ($hasGithubPayload) {
            $projectData['origen'] = empty($data['url_repositorio'])
                ? 'manual'
                : 'manual_github_editado';
        }

        if (($projectData['estado_publicacion'] ?? $project?->estado_publicacion) === 'publicado') {
            $projectData['publicado_at'] = $project?->publicado_at ?? now();
        }

        $githubData = [];

        if ($hasGithubPayload) {
            $githubData = [
                'github_url' => $this->nullableString($data['url_repositorio'] ?? null),
                'github_homepage' => $this->nullableString($data['url_demo'] ?? null),
            ];
        }

        return [
            'project' => $projectData,
            'visibility' => $visibility,
            'github' => $githubData,
            'tags' => $this->normalizeTags($data['etiquetas'] ?? []),
            'has_github_payload' => $hasGithubPayload,
            'has_tags_payload' => $hasTagsPayload,
        ];
    }

    private function resolveStates(array $data, ?Proyecto $project = null): array
    {
        $hasStatePayload = array_key_exists('estado', $data) || array_key_exists('en_curso', $data);

        if (! $hasStatePayload && $project) {
            return [null, null];
        }

        $estadoPublicacion = $project?->estado_publicacion ?? 'borrador';
        $estadoDesarrollo = $project?->estado_desarrollo ?? 'sin_especificar';

        if (array_key_exists('estado', $data)) {
            switch ($data['estado']) {
                case 'archivado':
                    $estadoPublicacion = 'archivado';
                    break;
                case 'borrador':
                    $estadoPublicacion = 'borrador';
                    break;
                case 'desarrollo':
                    $estadoPublicacion = 'publicado';
                    $estadoDesarrollo = 'en_desarrollo';
                    break;
                case 'publicado':
                default:
                    $estadoPublicacion = 'publicado';
                    if ($estadoDesarrollo === 'sin_especificar') {
                        $estadoDesarrollo = 'terminado';
                    }
                    break;
            }
        }

        if (array_key_exists('en_curso', $data)) {
            $inProgress = filter_var($data['en_curso'], FILTER_VALIDATE_BOOLEAN);

            if ($inProgress) {
                $estadoDesarrollo = 'en_desarrollo';
                if ($estadoPublicacion === 'borrador' && ! array_key_exists('estado', $data)) {
                    $estadoPublicacion = 'publicado';
                }
            } elseif ($estadoDesarrollo === 'en_desarrollo') {
                $estadoDesarrollo = 'terminado';
            }
        }

        return [$estadoPublicacion, $estadoDesarrollo];
    }

    private function syncGithubData(Proyecto $project, array $githubData): void
    {
        $hasAnyValue = collect($githubData)->filter(fn ($value) => ! empty($value))->isNotEmpty();

        if (! $hasAnyValue) {
            ProyectoGithub::query()
                ->where('id_proyecto', $project->id_proyecto)
                ->delete();

            return;
        }

        ProyectoGithub::updateOrCreate(
            ['id_proyecto' => $project->id_proyecto],
            $githubData + [
                'sync_status' => 'pendiente',
            ]
        );
    }

    private function syncTechnologies(Proyecto $project, array $tags): void
    {
        $keepIds = [];

        foreach ($tags as $index => $tag) {
            $technology = Tecnologia::firstOrCreate(
                ['nombre' => $tag],
                ['tipo' => 'otro']
            );

            $usage = UsoTecnologia::withTrashed()->firstOrNew([
                'id_proyecto' => $project->id_proyecto,
                'id_tecnologia' => $technology->id_tecnologia,
            ]);

            $usage->version_usada = null;
            $usage->porcentaje_uso = null;
            $usage->save();

            DB::table('uso_tecnologias')
                ->where('id_uso_tecnologia', $usage->id_uso_tecnologia)
                ->update([
                    'es_principal' => DB::raw($index === 0 ? 'true' : 'false'),
                    'es_visible' => DB::raw('true'),
                ]);

            if ($usage->trashed()) {
                $usage->restore();
            }

            $keepIds[] = $technology->id_tecnologia;
        }

        $query = UsoTecnologia::withTrashed()
            ->where('id_proyecto', $project->id_proyecto);

        if ($keepIds !== []) {
            $query->whereNotIn('id_tecnologia', $keepIds);
        }

        $query->forceDelete();
    }

    private function normalizeTags(array $tags): array
    {
        return collect($tags)
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->unique(fn ($tag) => Str::lower($tag))
            ->values()
            ->all();
    }

    private function mapOutgoingState(Proyecto $project): string
    {
        if ($project->estado_publicacion === 'archivado') {
            return 'archivado';
        }

        if ($project->estado_publicacion === 'borrador') {
            return 'borrador';
        }

        if ($project->estado_desarrollo === 'en_desarrollo') {
            return 'desarrollo';
        }

        return 'publicado';
    }

    private function resolveTypeId(?string $typeName): ?int
    {
        $typeName = $this->nullableString($typeName);

        if (! $typeName) {
            return null;
        }

        $type = TipoProyecto::firstOrCreate(
            ['nombre' => $typeName],
            ['orden' => 0]
        );

        return $type->id_tipo_proyecto;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function resolveOwnerId(Proyecto $project): int
    {
        $owner = $project->participaciones()
            ->whereRaw('es_propietario = true')
            ->orderByDesc('id_participacion')
            ->first();

        return $owner?->id_usuario ?? 0;
    }

    private function removeCoverRecord(ProyectoEvidencia $cover): void
    {
        if ($cover->archivo_path) {
            Storage::disk('public')->delete($cover->archivo_path);
        }

        $cover->forceDelete();
    }
}