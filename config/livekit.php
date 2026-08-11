<?php

return [
    'enabled' => (bool) env('LIVEKIT_ENABLED', false),
    'url' => env('LIVEKIT_PUBLIC_URL', env('LIVEKIT_URL', '')),
    'api_key' => env('LIVEKIT_API_KEY'),
    'api_secret' => env('LIVEKIT_API_SECRET'),
    'token_ttl' => (int) env('LIVEKIT_TOKEN_TTL', 300),
    // The server API uses HTTP/Twirp, while browsers use the public WSS URL.
    'egress_api_url' => env('LIVEKIT_EGRESS_API_URL', 'http://127.0.0.1:7880'),
    'recording_bucket' => env('LIVEKIT_RECORDING_BUCKET', 'netreverb-recordings'),
    'recording_s3_endpoint' => env('LIVEKIT_RECORDING_S3_ENDPOINT', 'http://127.0.0.1:9000'),
    'recording_s3_region' => env('LIVEKIT_RECORDING_S3_REGION', 'us-east-1'),
    'recording_s3_access_key' => env('LIVEKIT_RECORDING_S3_ACCESS_KEY'),
    'recording_s3_secret' => env('LIVEKIT_RECORDING_S3_SECRET'),
    'recording_width' => (int) env('LIVEKIT_RECORDING_WIDTH', 1280),
    'recording_height' => (int) env('LIVEKIT_RECORDING_HEIGHT', 720),
    'recording_framerate' => (int) env('LIVEKIT_RECORDING_FRAMERATE', 15),
    'recording_video_bitrate' => (int) env('LIVEKIT_RECORDING_VIDEO_BITRATE', 1800),
];
