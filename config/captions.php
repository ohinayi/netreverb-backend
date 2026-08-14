<?php

return [
    // Base ws(s):// URL of the standalone Node captions relay service. Never
    // dereferenced by Laravel itself - only used to build URLs handed to the
    // browser and to LiveKit's egress workers.
    'relay_url' => env('CAPTIONS_RELAY_URL', 'ws://127.0.0.1:8090'),

    // Shared HMAC secret with the relay (see App\Support\CaptionsRelayToken).
    // The Deepgram API key itself never lives here - only in the relay's own
    // env, since Laravel never talks to Deepgram.
    'relay_secret' => env('CAPTIONS_RELAY_SECRET'),

    'token_ttl' => (int) env('CAPTIONS_TOKEN_TTL', 300),
];
