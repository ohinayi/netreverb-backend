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
    'turn' => [
        'secret' => env('TURN_SECRET'),
        'host' => env('TURN_HOST', 'sip.classyra.com.ng'),
        'port' => (int) env('TURN_PORT', 3478),
        'tls_port' => (int) env('TURNS_PORT', 5349),
        'ttl' => min(600, max(300, (int) env('TURN_CREDENTIAL_TTL', 600))),
        'fallback_stun_url' => env('FALLBACK_STUN_URL', 'stun:stun.l.google.com:19302'),
    ],
];
