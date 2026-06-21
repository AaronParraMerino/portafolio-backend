<?php

namespace Tests\Feature;

use App\Services\api\Translation\Contracts\TranslationProvider;
use App\Services\api\Translation\Providers\MyMemoryTranslationProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MyMemoryTranslationProviderTest extends TestCase
{
    public function test_the_container_resolves_mymemory_when_it_is_configured(): void
    {
        config()->set('content_translation.provider', 'mymemory');

        $this->assertInstanceOf(
            MyMemoryTranslationProvider::class,
            app(TranslationProvider::class)
        );
    }

    public function test_it_sends_parameters_and_decodes_the_translated_text(): void
    {
        config()->set('content_translation.api_url', 'https://api.mymemory.translated.net/get');
        config()->set('content_translation.timeout', 5);
        config()->set('content_translation.email', 'translator@example.com');

        Http::fake([
            'https://api.mymemory.translated.net/get*' => Http::response([
                'responseData' => [
                    'translatedText' => 'Software &amp; services',
                ],
                'responseStatus' => 200,
            ]),
        ]);

        $translated = app(MyMemoryTranslationProvider::class)
            ->translate('Software y servicios', 'es', 'en');

        $this->assertSame('Software & services', $translated);

        Http::assertSent(function (Request $request) {
            return str_starts_with(
                $request->url(),
                'https://api.mymemory.translated.net/get'
            )
                && $request['q'] === 'Software y servicios'
                && $request['langpair'] === 'es|en'
                && $request['de'] === 'translator@example.com';
        });
    }

    public function test_it_returns_null_when_the_response_is_invalid(): void
    {
        config()->set('content_translation.api_url', 'https://api.mymemory.translated.net/get');
        config()->set('content_translation.email', null);
        Log::spy();

        Http::fake([
            'https://api.mymemory.translated.net/get*' => Http::response([
                'responseData' => [],
                'responseStatus' => 200,
            ]),
        ]);

        $translated = app(MyMemoryTranslationProvider::class)
            ->translate('Hola', 'es', 'pt');

        $this->assertNull($translated);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_it_returns_null_when_the_http_request_throws(): void
    {
        config()->set('content_translation.api_url', 'https://api.mymemory.translated.net/get');
        Log::spy();

        Http::fake(function () {
            throw new ConnectionException('Connection failed');
        });

        $translated = app(MyMemoryTranslationProvider::class)
            ->translate('Hola', 'es', 'en');

        $this->assertNull($translated);
        Log::shouldHaveReceived('warning')->once();
    }
}
