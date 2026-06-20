<?php

namespace App\Services\api\Translation\Contracts;

interface TranslationProvider
{
    public function translate(string $text, string $sourceLang, string $targetLang): ?string;
}
