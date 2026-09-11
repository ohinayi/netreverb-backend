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

    // Custom URL scheme the mobile app registers with the OS, so the OAuth
    // callback can hand a bearer token back to the app instead of setting a
    // browser cookie (mobile has no session cookie jar). The mobile app
    // requests this path by adding ?mobile=1 to the redirect URL.
    'mobile_callback_scheme' => env('OAUTH_MOBILE_CALLBACK_SCHEME', 'netreverb://oauth-callback'),
];
