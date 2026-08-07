<?php

return [
    'provider' => env('MESSAGE_TRANSLATION_PROVIDER', 'libretranslate'),
    'default_target_locale' => env('MESSAGE_TRANSLATION_DEFAULT_TARGET_LOCALE'),

    'providers' => [
        'libretranslate' => [
            'base_url' => env('LIBRETRANSLATE_URL', 'https://libretranslate.com'),
            'api_key' => env('LIBRETRANSLATE_API_KEY'),
            'connect_timeout' => (int) env('LIBRETRANSLATE_CONNECT_TIMEOUT', 5),
            'timeout' => (int) env('LIBRETRANSLATE_TIMEOUT', 20),
        ],
    ],
];
