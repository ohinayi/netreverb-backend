<?php

return [
    'sip_realm' => env('KAMAILIO_SIP_REALM', 'sip.classyra.com.ng'),
    'sip_server' => env('KAMAILIO_SIP_SERVER', 'sip.classyra.com.ng'),
    'sip_port' => (int) env('KAMAILIO_SIP_PORT', 5060),
    'websocket_url' => env('KAMAILIO_WS_URL', 'ws://sip.classyra.com.ng:8080'),
    'secure_websocket_url' => env('KAMAILIO_WSS_URL', 'wss://sip.classyra.com.ng:7443'),
    'sip_registration_expires' => (int) env('SIP_REGISTRATION_EXPIRES', 300),
    // Until a DID/SIP-trunk provider is connected, local and test routes can
    // be used immediately. Production should leave this disabled.
    'service_numbers' => [
        'auto_activate' => filter_var(env('SERVICE_NUMBERS_AUTO_ACTIVATE', false), FILTER_VALIDATE_BOOL),
    ],
    'automatic_extension_start' => (int) env('AUTOMATIC_EXTENSION_START', 302984),
    'automatic_extension_end' => (int) env('AUTOMATIC_EXTENSION_END', 399999),
    'conference_number_start' => (int) env('CONFERENCE_NUMBER_START', 45000000000),
    'conference_number_end' => (int) env('CONFERENCE_NUMBER_END', 45999999999),
    'conference_default_duration_minutes' => (int) env('CONFERENCE_DEFAULT_DURATION_MINUTES', 120),
    'conference_waiting_room' => [
        'max_pending' => (int) env('CONFERENCE_WAITING_ROOM_MAX_PENDING', 25),
        'request_ttl_minutes' => (int) env('CONFERENCE_WAITING_ROOM_REQUEST_TTL_MINUTES', 10),
    ],
    'conference_participants' => [
        'stale_after_seconds' => (int) env('CONFERENCE_PARTICIPANT_STALE_AFTER_SECONDS', 90),
        'missed_reconciliations_before_leave' => (int) env('CONFERENCE_PARTICIPANT_MISSED_RECONCILIATIONS_BEFORE_LEAVE', 2),
    ],
    'recordings' => [
        'disk' => env('FREESWITCH_RECORDINGS_DISK', 'freeswitch_recordings'),
        'base_path' => env('FREESWITCH_RECORDINGS_BASE_PATH', env('FREESWITCH_RECORDINGS_DIR', storage_path('app/public/recordings/conferences'))),
        'retention_days' => (int) env('FREESWITCH_RECORDING_RETENTION_DAYS', 30),
    ],
    'call_recordings' => [
        'disk' => env('FREESWITCH_CALL_RECORDINGS_DISK', 'freeswitch_call_recordings'),
        'base_path' => env('FREESWITCH_CALL_RECORDINGS_BASE_PATH', env('FREESWITCH_CALL_RECORDINGS_DIR', storage_path('app/public/recordings/calls'))),
        'retention_days' => (int) env('FREESWITCH_CALL_RECORDING_RETENTION_DAYS', 30),
        'announcement' => [
            'enabled' => env('FREESWITCH_CALL_RECORDING_ANNOUNCEMENT_ENABLED', true),
            'default_target' => env('FREESWITCH_CALL_RECORDING_ANNOUNCEMENT_DEFAULT_TARGET', 'both'),
            'default_audio_path' => env('FREESWITCH_CALL_RECORDING_ANNOUNCEMENT_DEFAULT_AUDIO_PATH', '/usr/local/freeswitch/sounds/custom/recording_notice.wav'),
            'allowed_audio_paths' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env(
                    'FREESWITCH_CALL_RECORDING_ANNOUNCEMENT_ALLOWED_AUDIO_PATHS',
                    env('FREESWITCH_CALL_RECORDING_ANNOUNCEMENT_DEFAULT_AUDIO_PATH', '/usr/local/freeswitch/sounds/custom/recording_notice.wav'),
                )),
            ))),
        ],
        'sync' => [
            'enabled' => env('FREESWITCH_CALL_RECORDINGS_AUTO_SYNC', true),
            'host' => env('FREESWITCH_CALL_RECORDINGS_SYNC_HOST', 'sip.classyra.com.ng'),
            'user' => env('FREESWITCH_CALL_RECORDINGS_SYNC_USER', 'deploy'),
            'password' => env('FREESWITCH_CALL_RECORDINGS_SYNC_PASSWORD'),
            'remote_base' => env('FREESWITCH_CALL_RECORDINGS_SYNC_REMOTE_BASE', '/usr/local/freeswitch/var/lib/freeswitch/recordings/calls'),
            // rsync never deletes its source, so every synced recording
            // otherwise sits on the VPS disk twice forever - once in
            // FreeSWITCH's own recordings folder, once in the app's synced
            // copy. Deletion only ever targets a file already confirmed
            // synced and at least 10 minutes old (see
            // SyncCallRecordingFromVps::deleteSyncedRemoteSource()).
            'remove_source_after_sync' => env('FREESWITCH_CALL_RECORDINGS_REMOVE_SOURCE_AFTER_SYNC', true),
        ],
        'direct_video_mux' => [
            'ffmpeg_binary' => env('FREESWITCH_CALL_RECORDINGS_DIRECT_VIDEO_MUX_FFMPEG_BINARY', 'ffmpeg'),
            'timeout_seconds' => (int) env('FREESWITCH_CALL_RECORDINGS_DIRECT_VIDEO_MUX_TIMEOUT_SECONDS', 180),
        ],
    ],
    'freeswitch' => [
        'event_socket_host' => env('FREESWITCH_EVENT_SOCKET_HOST', env('FREESWITCH_HOST', '127.0.0.1')),
        'event_socket_port' => (int) env('FREESWITCH_EVENT_SOCKET_PORT', env('FREESWITCH_PORT', 8021)),
        'event_socket_password' => env('FREESWITCH_PASSWORD', env('FREESWITCH_EVENT_SOCKET_PASSWORD')),
        'event_socket_timeout_seconds' => (int) env('FREESWITCH_EVENT_SOCKET_TIMEOUT_SECONDS', 5),
        'xml_curl_token' => env('FREESWITCH_XML_CURL_TOKEN'),
        'xml_curl_allowed_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('FREESWITCH_XML_CURL_ALLOWED_IPS', '127.0.0.1,::1')),
        ))),
        // The production XML-cURL router can send selected development
        // extensions through the reverse SSH tunnel while every other call
        // continues to use production data. This keeps one permanent
        // FreeSWITCH XML-CURL URL for both environments.
        'xml_curl_local_test_extensions' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('FREESWITCH_XML_CURL_LOCAL_TEST_EXTENSIONS', '')),
        ))),
        'xml_curl_local_tunnel_url' => rtrim((string) env('FREESWITCH_XML_CURL_LOCAL_TUNNEL_URL', ''), '/'),
        'transfer_dialplan' => env('FREESWITCH_TRANSFER_DIALPLAN', 'XML'),
        // Browser extensions are routed by the public dialplan.  Sending a
        // blind transfer to `default` hits its catch-all/sleep rule instead
        // of bridging the destination extension.
        'transfer_context' => env('FREESWITCH_TRANSFER_CONTEXT', 'public'),
        // Optional URL used when FreeSWITCH cannot read the Laravel storage
        // filesystem directly (for example local Laravel + VPS FreeSWITCH
        // through an SSH reverse tunnel). Use http_cache:// for FreeSWITCH.
        'ivr_audio_base_url' => rtrim((string) env('FREESWITCH_IVR_AUDIO_BASE_URL', ''), '/'),
    ],
    'turn' => [
        'secret' => env('TURN_SECRET'),
        'host' => env('TURN_HOST', 'sip.classyra.com.ng'),
        'port' => (int) env('TURN_PORT', 3478),
        'tls_port' => (int) env('TURNS_PORT', 5349),
        'ttl' => min(600, max(300, (int) env('TURN_CREDENTIAL_TTL', 600))),
        'fallback_stun_url' => env('FALLBACK_STUN_URL', 'stun:stun.l.google.com:19302'),
    ],
    'webrtc' => [
        'video_enabled' => env('WEBRTC_VIDEO_ENABLED', true),
        'video_max_bitrate_kbps' => (int) env('WEBRTC_VIDEO_MAX_BITRATE_KBPS', 1500),
        'recording' => [
            'direct_audio_enabled' => env('WEBRTC_RECORDING_DIRECT_AUDIO_ENABLED', true),
            'direct_video_enabled' => env('WEBRTC_RECORDING_DIRECT_VIDEO_ENABLED', false),
            'direct_video_strategy' => env('WEBRTC_RECORDING_DIRECT_VIDEO_STRATEGY', 'freeswitch'),
            'direct_audio_container' => env('WEBRTC_RECORDING_DIRECT_AUDIO_CONTAINER', 'wav'),
            'direct_video_container' => env('WEBRTC_RECORDING_DIRECT_VIDEO_CONTAINER', 'mp4'),
            'direct_video_start_command_template' => env('WEBRTC_RECORDING_DIRECT_VIDEO_START_COMMAND_TEMPLATE'),
            'direct_video_stop_command_template' => env('WEBRTC_RECORDING_DIRECT_VIDEO_STOP_COMMAND_TEMPLATE'),
            'direct_video_chunk_duration_ms' => (int) env('WEBRTC_RECORDING_DIRECT_VIDEO_CHUNK_DURATION_MS', 4000),
            'direct_video_max_chunk_size_kb' => (int) env('WEBRTC_RECORDING_DIRECT_VIDEO_MAX_CHUNK_SIZE_KB', 8192),
            'conference_audio_enabled' => env('WEBRTC_RECORDING_CONFERENCE_AUDIO_ENABLED', true),
            'conference_video_enabled' => env('WEBRTC_RECORDING_CONFERENCE_VIDEO_ENABLED', false),
            'conference_video_strategy' => env('WEBRTC_RECORDING_CONFERENCE_VIDEO_STRATEGY', 'client_chunks'),
            'conference_screen_share_enabled' => env('WEBRTC_RECORDING_CONFERENCE_SCREEN_SHARE_ENABLED', false),
            'conference_audio_container' => env('WEBRTC_RECORDING_CONFERENCE_AUDIO_CONTAINER', 'wav'),
            'conference_video_container' => env('WEBRTC_RECORDING_CONFERENCE_VIDEO_CONTAINER', 'mp4'),
            'conference_video_chunk_duration_ms' => (int) env('WEBRTC_RECORDING_CONFERENCE_VIDEO_CHUNK_DURATION_MS', 4000),
            'conference_video_max_chunk_size_kb' => (int) env('WEBRTC_RECORDING_CONFERENCE_VIDEO_MAX_CHUNK_SIZE_KB', 8192),
        ],
        'video' => [
            'width' => [
                'ideal' => (int) env('WEBRTC_VIDEO_WIDTH_IDEAL', 1280),
                'max' => (int) env('WEBRTC_VIDEO_WIDTH_MAX', 1920),
            ],
            'height' => [
                'ideal' => (int) env('WEBRTC_VIDEO_HEIGHT_IDEAL', 720),
                'max' => (int) env('WEBRTC_VIDEO_HEIGHT_MAX', 1080),
            ],
            'frame_rate' => [
                'ideal' => (int) env('WEBRTC_VIDEO_FRAME_RATE_IDEAL', 24),
                'max' => (int) env('WEBRTC_VIDEO_FRAME_RATE_MAX', 30),
            ],
            'facing_mode' => env('WEBRTC_VIDEO_FACING_MODE', 'user'),
        ],
    ],
];
