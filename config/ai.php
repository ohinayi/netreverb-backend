<?php

return [
    'provider' => env('AI_PROVIDER', 'gemini'),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
        'timeout_seconds' => (int) env('GEMINI_TIMEOUT_SECONDS', 30),
    ],

    'transcription' => [
        'enabled' => env('AI_TRANSCRIPTION_ENABLED', false),
        'provider' => env('TRANSCRIPTION_PROVIDER', 'whisper_cpp'),
        'whisper_cpp_url' => env('WHISPER_CPP_URL', 'http://127.0.0.1:8081'),
    ],
];
