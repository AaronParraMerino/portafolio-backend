<?php

namespace Tests\Feature;

use App\Services\api\Translation\Contracts\TranslationProvider;
use App\Services\api\Translation\Providers\GoogleTranslationProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GoogleTranslationProviderTest extends TestCase
{
    public function test_the_container_resolves_google_when_it_is_configured(): void
    {
        config()->set('content_translation.provider', 'google');

        $this->assertInstanceOf(
            GoogleTranslationProvider::class,
            app(TranslationProvider::class)
        );
    }

    public function test_it_translates_text_and_decodes_html_entities(): void
    {
        config()->set('content_translation.api_url', 'https://translation.googleapis.com/language/translate/v2');
        config()->set('content_translation.api_key', 'test-key');
        config()->set('content_translation.timeout', 5);

        Http::fake([
            'https://translation.googleapis.com/language/translate/v2' => Http::response([
                'data' => [
                    'translations' => [
                        ['translatedText' => 'Software &amp; servicios'],
                    ],
                ],
            ]),
        ]);

        $translated = app(GoogleTranslationProvider::class)
            ->translate('Software y servicios', 'es', 'en');

        $this->assertSame('Software & servicios', $translated);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://translation.googleapis.com/language/translate/v2'
                && $request['q'] === 'Software y servicios'
                && $request['source'] === 'es'
                && $request['target'] === 'en'
                && $request['format'] === 'text'
                && $request['key'] === 'test-key';
        });
    }

    public function test_it_returns_null_without_an_api_key_and_does_not_send_a_request(): void
    {
        config()->set('content_translation.api_key', '');
        Log::spy();
        Http::fake();

        $translated = app(GoogleTranslationProvider::class)
            ->translate('Hola', 'es', 'en');

        $this->assertNull($translated);
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }
}
