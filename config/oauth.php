<?php

use Illuminate\Support\Str;

return [
    'enabled_providers' => array_values(array_filter(array_map(
        static fn (string $provider): string => Str::lower(trim($provider)),
        explode(',', (string) env('OAUTH_ENABLED_PROVIDERS', 'google')),
    ))),

    'providers' => [
        'google' => [
            'name' => 'Google',
        ],
    ],

    'default_account_type' => env('OAUTH_DEFAULT_ACCOUNT_TYPE', 'individual'),
];
