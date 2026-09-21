<?php

return [

    'voice_messages' => [

        // The disk voice recordings are stored on - same "public" convention
        // used for other user-uploaded audio (see OrganizationIvrController).
        'disk' => env('VOICE_MESSAGE_DISK', 'public'),

        'max_duration_ms' => (int) env('VOICE_MESSAGE_MAX_DURATION_MS', 120_000),

        'max_file_size_kb' => (int) env('VOICE_MESSAGE_MAX_FILE_SIZE_KB', 8192),

        'allowed_mime_types' => [
            'audio/webm',
            'audio/ogg',
            'video/webm',
            'audio/mp4',
            'audio/x-m4a',
            'audio/aac',
            'audio/mpeg',
        ],

    ],

];
