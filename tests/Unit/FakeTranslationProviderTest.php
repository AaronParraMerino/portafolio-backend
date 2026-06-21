<?php

namespace Tests\Unit;

use App\Services\api\Translation\Providers\FakeTranslationProvider;
use PHPUnit\Framework\TestCase;

class FakeTranslationProviderTest extends TestCase
{
    public function test_it_prefixes_the_original_text_with_the_target_language(): void
    {
        $provider = new FakeTranslationProvider();

        $this->assertSame('[en] Hola mundo', $provider->translate('Hola mundo', 'es', 'en'));
        $this->assertSame('[pt] Hola mundo', $provider->translate('Hola mundo', 'es', 'pt'));
    }
}
