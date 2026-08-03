<?php

return [
    'enabled' => (bool) env('PAYMENTS_ENABLED', false),
    'default_gateway' => env('PAYMENTS_DEFAULT_GATEWAY', 'paystack'),

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    ],

    'flutterwave' => [
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'webhook_secret_hash' => env('FLUTTERWAVE_WEBHOOK_SECRET_HASH'),
        'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
    ],
];
