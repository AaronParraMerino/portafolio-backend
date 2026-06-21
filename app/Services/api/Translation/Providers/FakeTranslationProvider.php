<?php

namespace App\Services\api\Translation\Providers;

use App\Services\api\Translation\Contracts\TranslationProvider;

class FakeTranslationProvider implements TranslationProvider
{
    public function translate(string $text, string $sourceLang, string $targetLang): ?string
    {
        return sprintf('[%s] %s', strtolower($targetLang), $text);
    }
}
