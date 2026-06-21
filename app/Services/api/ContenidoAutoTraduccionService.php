<?php

namespace App\Services\api;

use App\Models\TraduccionContenido;
use App\Services\api\Translation\Contracts\TranslationProvider;
use Illuminate\Support\Facades\Log;

class ContenidoAutoTraduccionService
{
    public function __construct(private readonly TranslationProvider $provider)
    {
    }

    public function traducirEntidad(
        string $entidadTipo,
        int $entidadId,
        ?int $usuarioId,
        array $campos
    ): void {
        if (! config('content_translation.enabled', false)) {
            return;
        }

        $sourceLang = (string) config('content_translation.source_lang', 'es');
        $targetLangs = (array) config('content_translation.target_langs', ['en', 'pt']);

        foreach ($campos as $campo => $textoOriginal) {
            if (! is_string($textoOriginal) || trim($textoOriginal) === '') {
                $this->eliminarCampo($entidadTipo, $entidadId, (string) $campo);
                continue;
            }

            $textoOriginal = trim($textoOriginal);

            foreach ($targetLangs as $targetLang) {
                $targetLang = strtolower(trim((string) $targetLang));

                if ($targetLang === '' || $targetLang === $sourceLang) {
                    continue;
                }

                try {
                    $existente = TraduccionContenido::query()
                        ->where('entidad_tipo', $entidadTipo)
                        ->where('entidad_id', $entidadId)
                        ->where('campo', $campo)
                        ->where('idioma', $targetLang)
                        ->first();

                    if ($existente?->origen === 'manual' && $existente?->estado === 'revisado') {
                        continue;
                    }

                    $textoTraducido = $this->provider->translate($textoOriginal, $sourceLang, $targetLang);

                    if (! is_string($textoTraducido) || trim($textoTraducido) === '') {
                        Log::warning('El proveedor devolvio una traduccion vacia.', [
                            'entidad_tipo' => $entidadTipo,
                            'entidad_id' => $entidadId,
                            'campo' => $campo,
                            'idioma' => $targetLang,
                        ]);
                        continue;
                    }

                    TraduccionContenido::updateOrCreate(
                        [
                            'entidad_tipo' => $entidadTipo,
                            'entidad_id' => $entidadId,
                            'campo' => $campo,
                            'idioma' => $targetLang,
                        ],
                        [
                            'usuario_id' => $usuarioId,
                            'texto_traducido' => trim($textoTraducido),
                            'origen' => 'automatico',
                            'estado' => 'traducido',
                        ]
                    );
                } catch (\Throwable $exception) {
                    Log::warning('No se pudo generar una traduccion automatica.', [
                        'entidad_tipo' => $entidadTipo,
                        'entidad_id' => $entidadId,
                        'campo' => $campo,
                        'idioma' => $targetLang,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }

    public function eliminarCampo(string $entidadTipo, int $entidadId, string $campo): void
    {
        try {
            TraduccionContenido::query()
                ->where('entidad_tipo', $entidadTipo)
                ->where('entidad_id', $entidadId)
                ->where('campo', $campo)
                ->delete();
        } catch (\Throwable $exception) {
            Log::warning('No se pudieron eliminar traducciones de un campo.', [
                'entidad_tipo' => $entidadTipo,
                'entidad_id' => $entidadId,
                'campo' => $campo,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function eliminarEntidad(string $entidadTipo, int $entidadId): void
    {
        try {
            TraduccionContenido::query()
                ->where('entidad_tipo', $entidadTipo)
                ->where('entidad_id', $entidadId)
                ->delete();
        } catch (\Throwable $exception) {
            Log::warning('No se pudieron eliminar traducciones de una entidad.', [
                'entidad_tipo' => $entidadTipo,
                'entidad_id' => $entidadId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
