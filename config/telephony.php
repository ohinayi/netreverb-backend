<?php

return [
    'sip_realm' => env('KAMAILIO_SIP_REALM', 'sip.classyra.com.ng'),
    'sip_server' => env('KAMAILIO_SIP_SERVER', 'sip.classyra.com.ng'),
    'sip_port' => (int) env('KAMAILIO_SIP_PORT', 5060),
    'websocket_url' => env('KAMAILIO_WS_URL', 'ws://sip.classyra.com.ng:8080'),
    'secure_websocket_url' => env('KAMAILIO_WSS_URL', 'wss://sip.classyra.com.ng:7443'),
    'sip_registration_expires' => (int) env('SIP_REGISTRATION_EXPIRES', 300),
    'automatic_extension_start' => (int) env('AUTOMATIC_EXTENSION_START', 302984),
    'automatic_extension_end' => (int) env('AUTOMATIC_EXTENSION_END', 399999),
    'conference_number_start' => (int) env('CONFERENCE_NUMBER_START', 45000000000),
    'conference_number_end' => (int) env('CONFERENCE_NUMBER_END', 45999999999),
    'conference_default_duration_minutes' => (int) env('CONFERENCE_DEFAULT_DURATION_MINUTES', 120),
    'recordings' => [
        'disk' => env('FREESWITCH_RECORDINGS_DISK', 'freeswitch_recordings'),
        'base_path' => env('FREESWITCH_RECORDINGS_BASE_PATH', env('FREESWITCH_RECORDINGS_DIR', storage_path('app/freeswitch/recordings/conferences'))),
        'retention_days' => (int) env('FREESWITCH_RECORDING_RETENTION_DAYS', 30),
    ],
    'call_recordings' => [
        'disk' => env('FREESWITCH_CALL_RECORDINGS_DISK', 'freeswitch_call_recordings'),
        'base_path' => env('FREESWITCH_CALL_RECORDINGS_BASE_PATH', env('FREESWITCH_CALL_RECORDINGS_DIR', storage_path('app/freeswitch/recordings/calls'))),
        'retention_days' => (int) env('FREESWITCH_CALL_RECORDING_RETENTION_DAYS', 30),
    ],
    'freeswitch' => [
        'event_socket_host' => env('FREESWITCH_EVENT_SOCKET_HOST', env('FREESWITCH_HOST', '127.0.0.1')),
        'event_socket_port' => (int) env('FREESWITCH_EVENT_SOCKET_PORT', env('FREESWITCH_PORT', 8021)),
        'event_socket_password' => env('FREESWITCH_EVENT_SOCKET_PASSWORD', env('FREESWITCH_PASSWORD', 'ClueCon')),
        'event_socket_timeout_seconds' => (int) env('FREESWITCH_EVENT_SOCKET_TIMEOUT_SECONDS', 5),
    ],
    'turn' => [
        'secret' => env('TURN_SECRET'),
        'host' => env('TURN_HOST', 'sip.classyra.com.ng'),
        'port' => (int) env('TURN_PORT', 3478),
        'tls_port' => (int) env('TURNS_PORT', 5349),
        'ttl' => min(600, max(300, (int) env('TURN_CREDENTIAL_TTL', 600))),
        'fallback_stun_url' => env('FALLBACK_STUN_URL', 'stun:stun.l.google.com:19302'),
    ],
];
