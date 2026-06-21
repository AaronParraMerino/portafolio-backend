<?php

namespace App\Services\api\Translation\Providers;

use App\Services\api\Translation\Contracts\TranslationProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleTranslationProvider implements TranslationProvider
{
    private const DEFAULT_API_URL = 'https://translation.googleapis.com/language/translate/v2';

    public function translate(string $text, string $sourceLang, string $targetLang): ?string
    {
        $apiKey = trim((string) config('content_translation.api_key'));

        if ($apiKey === '') {
            Log::warning('Google Translation no esta configurado: falta la API key.');

            return null;
        }

        $apiUrl = trim((string) config('content_translation.api_url')) ?: self::DEFAULT_API_URL;
        $timeout = max(1, (int) config('content_translation.timeout', 5));

        try {
            $response = Http::timeout($timeout)
                ->asForm()
                ->post($apiUrl, [
                    'q' => $text,
                    'source' => $sourceLang,
                    'target' => $targetLang,
                    'format' => 'text',
                    'key' => $apiKey,
                ]);

            if (! $response->successful()) {
                Log::warning('Google Translation respondio con error.', [
                    'status' => $response->status(),
                    'source_lang' => $sourceLang,
                    'target_lang' => $targetLang,
                ]);

                return null;
            }

            $translatedText = $response->json('data.translations.0.translatedText');

            if (! is_string($translatedText) || trim($translatedText) === '') {
                Log::warning('Google Translation no devolvio texto traducido.', [
                    'source_lang' => $sourceLang,
                    'target_lang' => $targetLang,
                ]);

                return null;
            }

            return html_entity_decode(trim($translatedText), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } catch (\Throwable $exception) {
            Log::warning('Fallo la solicitud a Google Translation.', [
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
