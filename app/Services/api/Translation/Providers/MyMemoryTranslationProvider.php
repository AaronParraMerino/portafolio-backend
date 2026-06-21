<?php

namespace App\Services\api\Translation\Providers;

use App\Services\api\Translation\Contracts\TranslationProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MyMemoryTranslationProvider implements TranslationProvider
{
    private const DEFAULT_API_URL = 'https://api.mymemory.translated.net/get';

    public function translate(string $text, string $sourceLang, string $targetLang): ?string
    {
        $apiUrl = trim((string) config('content_translation.api_url')) ?: self::DEFAULT_API_URL;
        $timeout = max(1, (int) config('content_translation.timeout', 5));
        $email = trim((string) config('content_translation.email'));

        $query = [
            'q' => $text,
            'langpair' => strtolower($sourceLang).'|'.strtolower($targetLang),
        ];

        if ($email !== '') {
            $query['de'] = $email;
        }

        try {
            $response = Http::timeout($timeout)->get($apiUrl, $query);

            if (! $response->successful()) {
                Log::warning('MyMemory Translation respondio con error HTTP.', [
                    'status' => $response->status(),
                    'source_lang' => $sourceLang,
                    'target_lang' => $targetLang,
                ]);

                return null;
            }

            $responseStatus = $response->json('responseStatus');
            if ($responseStatus !== null && (int) $responseStatus !== 200) {
                Log::warning('MyMemory Translation reporto un error.', [
                    'response_status' => $responseStatus,
                    'response_details' => $response->json('responseDetails'),
                    'source_lang' => $sourceLang,
                    'target_lang' => $targetLang,
                ]);

                return null;
            }

            $translatedText = $response->json('responseData.translatedText');

            if (! is_string($translatedText) || trim($translatedText) === '') {
                Log::warning('MyMemory Translation no devolvio texto traducido.', [
                    'source_lang' => $sourceLang,
                    'target_lang' => $targetLang,
                ]);

                return null;
            }

            return html_entity_decode(trim($translatedText), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } catch (\Throwable $exception) {
            Log::warning('Fallo la solicitud a MyMemory Translation.', [
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
