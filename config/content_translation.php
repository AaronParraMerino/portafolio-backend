<?php

return [
    'enabled' => filter_var(env('CONTENT_TRANSLATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'provider' => env('CONTENT_TRANSLATION_PROVIDER', 'fake'),
    'api_url' => env('CONTENT_TRANSLATION_API_URL'),
    'api_key' => env('CONTENT_TRANSLATION_API_KEY'),
    'email' => env('CONTENT_TRANSLATION_EMAIL'),
    'source_lang' => env('CONTENT_TRANSLATION_SOURCE_LANG', 'es'),
    'target_langs' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CONTENT_TRANSLATION_TARGET_LANGS', 'en,pt'))
    ))),
    'timeout' => (int) env('CONTENT_TRANSLATION_TIMEOUT', 5),
];
