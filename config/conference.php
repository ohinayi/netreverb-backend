<?php

return [
    'presence' => [
        'heartbeat_interval_seconds' => (int) env('CONFERENCE_PRESENCE_HEARTBEAT_INTERVAL_SECONDS', 15),
        'timeout_seconds' => (int) env('CONFERENCE_PRESENCE_TIMEOUT_SECONDS', 40),
        'missed_reconciliations_before_disconnect' => (int) env('CONFERENCE_PRESENCE_MISSED_RECONCILIATIONS_BEFORE_DISCONNECT', 2),
    ],
    'chat' => [
        'websocket_url' => env('CONFERENCE_CHAT_WEBSOCKET_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/conference-rooms/{conferenceRoom}/chat'),
        'stream_url' => env('CONFERENCE_CHAT_STREAM_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/conference-rooms/{conferenceRoom}/chat/stream'),
        'history_limit' => (int) env('CONFERENCE_CHAT_HISTORY_LIMIT', 50),
        'rate_limit_max_messages' => (int) env('CONFERENCE_CHAT_RATE_LIMIT_MAX_MESSAGES', 10),
        'rate_limit_decay_seconds' => (int) env('CONFERENCE_CHAT_RATE_LIMIT_DECAY_SECONDS', 10),
    ],
];
