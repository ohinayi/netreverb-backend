<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'freeswitch_recordings' => [
            'driver' => 'local',
            'root' => env('FREESWITCH_RECORDINGS_PATH', storage_path('app/public/recordings/conferences')),
            'serve' => false,
            'throw' => false,
            'report' => false,
            'visibility' => 'public',
            'permissions' => [
                'file' => ['public' => 0664, 'private' => 0600],
                'dir' => ['public' => 0775, 'private' => 0700],
            ],
        ],

        'freeswitch_call_recordings' => [
            'driver' => 'local',
            'root' => env('FREESWITCH_CALL_RECORDINGS_PATH', storage_path('app/public/recordings/calls')),
            'serve' => false,
            'throw' => false,
            'report' => false,
            'visibility' => 'public',
            'permissions' => [
                'file' => ['public' => 0664, 'private' => 0600],
                'dir' => ['public' => 0775, 'private' => 0700],
            ],
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Same bucket/credentials Egress uploads track recordings to (see
        // config/livekit.php). The combine job reads per-track files and
        // writes the merged recording back here.
        'livekit_recordings' => [
            'driver' => 's3',
            'key' => env('LIVEKIT_RECORDING_S3_ACCESS_KEY'),
            'secret' => env('LIVEKIT_RECORDING_S3_SECRET'),
            'region' => env('LIVEKIT_RECORDING_S3_REGION', 'us-east-1'),
            'bucket' => env('LIVEKIT_RECORDING_BUCKET', 'netreverb-recordings'),
            'endpoint' => env('LIVEKIT_RECORDING_S3_ENDPOINT', 'http://127.0.0.1:9000'),
            'use_path_style_endpoint' => true,
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
