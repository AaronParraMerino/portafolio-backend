<?php

namespace App\Services\api;

use App\Models\PersonalizacionPortafolio;
use Illuminate\Support\Facades\DB;

class PersonalizacionPortafolioService
{
    private const PROFILE_FIELDS = [
        'nombre',
        'profesion',
        'ubicacion',
        'telefono',
        'correo',
        'redes',
        'biografia',
    ];

    private const STAT_FIELDS = [
        'proyectos',
        'tecnologias',
        'academica',
        'laboral',
    ];

    public function getByUser(int $userId): ?array
    {
        $personalizacion = PersonalizacionPortafolio::where('usuario_id', $userId)->first();

        return $personalizacion ? $this->serialize($personalizacion) : null;
    }

    public function saveForUser(int $userId, array $data): array
    {
        $data = $this->normalizeBooleanFields($data);
        $hasVisibility = array_key_exists('visibilidad', $data);

        if ($hasVisibility) {
            $data['visibilidad'] = $this->normalizeVisibility($data['visibilidad']);
        }

        return DB::transaction(function () use ($userId, $data, $hasVisibility) {
            $personalizacion = PersonalizacionPortafolio::where('usuario_id', $userId)->first();

            if ($personalizacion) {
                $personalizacion->update($data);
            } else {
                $payload = [
                    'usuario_id' => $userId,
                    ...$this->defaults(),
                    ...$data,
                ];

                $payload = $this->normalizeBooleanFields($payload);

                if (array_key_exists('visibilidad', $payload)) {
                    $payload['visibilidad'] = $this->normalizeVisibility($payload['visibilidad']);
                }

                $personalizacion = PersonalizacionPortafolio::create($payload);
            }

            if ($hasVisibility) {
                $this->syncVisibilityToContentTables($userId, $data['visibilidad']);
            }

            return $this->serialize($personalizacion->fresh());
        });
    }

    private function defaults(): array
    {
        return [
            'hero_color' => '#0c1a2e',
            'hero_bg_source' => 'custom',
            'hero_pattern' => 'dots',
            'avatar_bg_source' => 'foto',
            'avatar_color' => '#0c1a2e',
            'accent_color' => '#0077b7',
            'card_bg' => '#ffffff',
            'text_color_auto' => 'true',
            'text_color' => '#111827',
            'font_id' => 'inter',
            'frame_id' => 'mac',
            'disponible' => 'false',
            'visibilidad' => $this->defaultVisibility(),
        ];
    }

    private function normalizeBooleanFields(array $data): array
    {
        foreach (['text_color_auto', 'disponible'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
                $data[$field] = 'true';
            } else {
                $data[$field] = 'false';
            }
        }

        return $data;
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 't', 'yes', 'on'], true);
        }

        return false;
    }

    private function pgBoolean(mixed $value)
    {
        return DB::raw($this->toBoolean($value) ? 'true' : 'false');
    }

    private function defaultVisibility(): array
    {
        return [
            'perfil' => [
                'nombre' => true,
                'profesion' => true,
                'ubicacion' => true,
                'telefono' => true,
                'correo' => true,
                'redes' => true,
                'biografia' => true,
            ],
            'stats' => [
                'proyectos' => true,
                'tecnologias' => true,
                'academica' => true,
                'laboral' => true,
            ],
            'habilidades' => [],
            'experiencias' => [],
            'proyectos' => [],
        ];
    }

    private function normalizeVisibility(mixed $visibility): array
    {
        if (is_string($visibility)) {
            $decoded = json_decode($visibility, true);
            $visibility = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($visibility)) {
            $visibility = [];
        }

        $normalized = $this->defaultVisibility();

        foreach (self::PROFILE_FIELDS as $field) {
            if (array_key_exists($field, $visibility['perfil'] ?? [])) {
                $normalized['perfil'][$field] = $this->toBooleanWithFallback(
                    $visibility['perfil'][$field],
                    true
                );
            }
        }

        foreach (self::STAT_FIELDS as $field) {
            if (array_key_exists($field, $visibility['stats'] ?? [])) {
                $normalized['stats'][$field] = $this->toBooleanWithFallback(
                    $visibility['stats'][$field],
                    true
                );
            }
        }

        foreach (['habilidades', 'experiencias', 'proyectos'] as $group) {
            $normalized[$group] = $this->normalizeDynamicVisibility($visibility[$group] ?? []);
        }

        return $normalized;
    }

    private function normalizeDynamicVisibility(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $key => $value) {
            $id = trim((string) $key);

            if ($id === '' || strlen($id) > 80) {
                continue;
            }

            if (! preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
                continue;
            }

            $normalized[$id] = $this->toBooleanWithFallback($value, true);
        }

        return $normalized;
    }

    private function toBooleanWithFallback(mixed $value, bool $fallback): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 't', 'yes', 'on', 'publico'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'f', 'no', 'off', 'privado'], true)) {
                return false;
            }
        }

        return $fallback;
    }

    private function syncVisibilityToContentTables(int $userId, array $visibility): void
    {
        $this->syncProfileVisibility($userId, $visibility['perfil'] ?? []);
        $this->syncSkillsVisibility($userId, $visibility['habilidades'] ?? []);
        $this->syncExperiencesVisibility($userId, $visibility['experiencias'] ?? []);
        $this->syncProjectsVisibility($userId, $visibility['proyectos'] ?? []);
    }

    private function syncProfileVisibility(int $userId, array $visibility): void
    {
        $rows = [];
        $profileMap = [
            'profesion' => ['profesion'],
            'telefono' => ['telefono'],
            'correo' => ['correo'],
            'biografia' => ['biografia'],
            'ubicacion' => ['ciudad', 'pais'],
        ];

        foreach ($profileMap as $viewField => $databaseFields) {
            if (! array_key_exists($viewField, $visibility)) {
                continue;
            }

            foreach ($databaseFields as $databaseField) {
                $rows[] = [
                    'usuario_id' => $userId,
                    'campo' => $databaseField,
                    'visible' => $this->pgBoolean($visibility[$viewField]),
                ];
            }
        }

        if (! empty($rows)) {
            DB::table('visibilidad_campos')->upsert(
                $rows,
                ['usuario_id', 'campo'],
                ['visible']
            );
        }

        if (array_key_exists('redes', $visibility)) {
            DB::table('enlaces')
                ->where('id_usuario', $userId)
                ->update(['es_visible' => $this->pgBoolean($visibility['redes'])]);
        }
    }

    private function syncSkillsVisibility(int $userId, array $visibility): void
    {
        $updates = $this->extractVisibilityIds($visibility, 'habilidad');
        $this->updateBooleanGroups(
            'habilidades_usuario',
            'id_habilidad_usuario',
            'usuario_id',
            $userId,
            'es_visible',
            $updates
        );
    }

    private function syncExperiencesVisibility(int $userId, array $visibility): void
    {
        $updates = $this->extractVisibilityIds($visibility, 'experiencia');
        $this->updateBooleanGroups(
            'experiencias',
            'id_experiencia',
            'usuario_id',
            $userId,
            'es_publico',
            $updates
        );
    }

    private function syncProjectsVisibility(int $userId, array $visibility): void
    {
        $updates = $this->extractVisibilityIds($visibility, 'proyecto');

        foreach ([true, false] as $visible) {
            $ids = array_keys(array_filter(
                $updates,
                fn (bool $value) => $value === $visible
            ));

            if (empty($ids)) {
                continue;
            }

            DB::table('participaciones')
                ->where('id_usuario', $userId)
                ->whereIn('id_proyecto', $ids)
                ->whereNull('deleted_at')
                ->update(['visibilidad' => $visible ? 'publico' : 'privado']);
        }
    }

    private function extractVisibilityIds(array $visibility, string $prefix): array
    {
        $updates = [];

        foreach ($visibility as $key => $visible) {
            $raw = (string) $key;

            if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', $raw, $matches)) {
                $updates[(int) $matches[1]] = (bool) $visible;
                continue;
            }

            if (ctype_digit($raw)) {
                $updates[(int) $raw] = (bool) $visible;
            }
        }

        return $updates;
    }

    private function updateBooleanGroups(
        string $table,
        string $idColumn,
        string $ownerColumn,
        int $userId,
        string $visibilityColumn,
        array $updates
    ): void {
        foreach ([true, false] as $visible) {
            $ids = array_keys(array_filter(
                $updates,
                fn (bool $value) => $value === $visible
            ));

            if (empty($ids)) {
                continue;
            }

            DB::table($table)
                ->where($ownerColumn, $userId)
                ->whereIn($idColumn, $ids)
                ->update([$visibilityColumn => $this->pgBoolean($visible)]);
        }
    }

    private function serialize(PersonalizacionPortafolio $personalizacion): array
    {
        return [
            'usuario_id' => $personalizacion->usuario_id,
            'hero_color' => $personalizacion->hero_color,
            'hero_bg_source' => $personalizacion->hero_bg_source,
            'hero_pattern' => $personalizacion->hero_pattern,
            'avatar_bg_source' => $personalizacion->avatar_bg_source,
            'avatar_color' => $personalizacion->avatar_color,
            'accent_color' => $personalizacion->accent_color,
            'card_bg' => $personalizacion->card_bg,
            'text_color_auto' => $this->toBoolean($personalizacion->text_color_auto),
            'text_color' => $personalizacion->text_color,
            'font_id' => $personalizacion->font_id,
            'frame_id' => $personalizacion->frame_id,
            'disponible' => $this->toBoolean($personalizacion->disponible),
            'visibilidad' => $this->normalizeVisibility($personalizacion->visibilidad ?? []),
        ];
    }
}
