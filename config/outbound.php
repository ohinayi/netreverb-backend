<?php

return [
    'sending_enabled' => (bool) env('OUTBOUND_MESSAGING_ENABLED', false),
    'provider' => env('OUTBOUND_MESSAGING_PROVIDER', 'disabled'),
    'webhook_secret' => env('OUTBOUND_MESSAGING_WEBHOOK_SECRET'),
    'default_timezone' => env('OUTBOUND_MESSAGING_TIMEZONE', 'Africa/Lagos'),
    'default_rate_limit_per_minute' => (int) env('OUTBOUND_MESSAGING_RATE_LIMIT', 30),
    'quiet_hours_start' => env('OUTBOUND_MESSAGING_QUIET_START', '20:00'),
    'quiet_hours_end' => env('OUTBOUND_MESSAGING_QUIET_END', '08:00'),
    'billing' => [
        'cost_per_unit_minor' => (int) env('SMS_COST_PER_UNIT_MINOR', 200),
        'selling_per_unit_minor' => (int) env('SMS_SELLING_PER_UNIT_MINOR', 500),
        'minimum_purchase_minor' => (int) env('SMS_MINIMUM_PURCHASE_MINOR', 500000),
    ],
    'providers' => [
        'ebulksms' => [
            'endpoint' => env('EBULKSMS_ENDPOINT', 'https://api.ebulksms.com/sendsms.json'),
            'username' => env('EBULKSMS_USERNAME'),
            'api_key' => env('EBULKSMS_KEY'),
            'sender' => env('EBULKSMS_SENDER'),
            'dnd_sender' => (bool) env('EBULKSMS_DND_SENDER', false),
            'timeout' => (int) env('EBULKSMS_TIMEOUT', 20),
        ],
    ],
];
