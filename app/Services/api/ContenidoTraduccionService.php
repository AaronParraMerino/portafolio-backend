<?php

namespace App\Services\api;

use App\Models\TraduccionContenido;

class ContenidoTraduccionService
{
    private const IDIOMA_BASE = 'es';

    public function normalizarIdioma(?string $lang): string
    {
        $lang = strtolower(trim((string) $lang));

        return in_array($lang, ['es', 'en', 'pt'], true) ? $lang : self::IDIOMA_BASE;
    }

    public function traducirCampo(
        string $entidadTipo,
        int|string|null $entidadId,
        string $campo,
        mixed $valorOriginal,
        ?string $lang
    ): mixed {
        $idioma = $this->normalizarIdioma($lang);

        if ($idioma === self::IDIOMA_BASE) {
            return $valorOriginal;
        }

        if ($entidadId === null || $entidadId === '') {
            return $valorOriginal;
        }

        $textoOriginal = is_string($valorOriginal) ? trim($valorOriginal) : $valorOriginal;

        if (! is_string($textoOriginal) || $textoOriginal === '') {
            return $valorOriginal;
        }

        $traduccion = TraduccionContenido::query()
            ->where('entidad_tipo', $entidadTipo)
            ->where('entidad_id', (int) $entidadId)
            ->where('campo', $campo)
            ->where('idioma', $idioma)
            ->whereIn('estado', ['traducido', 'revisado'])
            ->value('texto_traducido');

        return $traduccion ?: $valorOriginal;
    }

    public function traducirArray(
        array $item,
        string $entidadTipo,
        int|string|null $entidadId,
        array $campos,
        ?string $lang
    ): array {
        foreach ($campos as $campo) {
            if (! array_key_exists($campo, $item)) {
                continue;
            }

            $item[$campo] = $this->traducirCampo(
                $entidadTipo,
                $entidadId,
                $campo,
                $item[$campo],
                $lang
            );
        }

        return $item;
    }
}