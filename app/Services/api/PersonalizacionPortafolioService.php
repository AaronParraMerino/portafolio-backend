<?php

namespace App\Services\api;

use App\Models\PersonalizacionPortafolio;

class PersonalizacionPortafolioService
{
    public function getByUser(int $userId): ?array
    {
        $personalizacion = PersonalizacionPortafolio::where('usuario_id', $userId)->first();

        return $personalizacion ? $this->serialize($personalizacion) : null;
    }

    public function saveForUser(int $userId, array $data): array
    {
        $data = $this->normalizeBooleanFields($data);

        $personalizacion = PersonalizacionPortafolio::where('usuario_id', $userId)->first();

        if ($personalizacion) {
            $personalizacion->update($data);

            return $this->serialize($personalizacion->fresh());
        }

        $payload = [
            'usuario_id' => $userId,
            ...$this->defaults(),
            ...$data,
        ];

        $payload = $this->normalizeBooleanFields($payload);

        $personalizacion = PersonalizacionPortafolio::create($payload);

        return $this->serialize($personalizacion);
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
        ];
    }
}