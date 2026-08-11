<?php

return [
    'driver' => env('IVR_TTS_DRIVER', 'piper'),
    'piper' => [
        'binary' => env('PIPER_BINARY', '/opt/piper/venv/bin/piper'),
        'model' => env('PIPER_MODEL', '/opt/piper/voices/en_US-lessac-medium.onnx'),
        'length_scale' => env('PIPER_LENGTH_SCALE', '1.0'),
        'output_disk' => env('PIPER_OUTPUT_DISK', 'public'),
        'voices' => [
            'en_US-lessac-medium' => [
                'label' => 'Lessac',
                'description' => 'US English · warm and clear',
                'model' => env('PIPER_VOICE_LESSAC_MODEL', env('PIPER_MODEL', '/opt/piper/voices/en_US-lessac-medium.onnx')),
            ],
            'en_US-amy-medium' => [
                'label' => 'Amy',
                'description' => 'US English · female',
                'model' => env('PIPER_VOICE_AMY_MODEL', '/opt/piper/voices/en_US-amy-medium.onnx'),
            ],
            'en_US-ryan-medium' => [
                'label' => 'Ryan',
                'description' => 'US English · male',
                'model' => env('PIPER_VOICE_RYAN_MODEL', '/opt/piper/voices/en_US-ryan-medium.onnx'),
            ],
            'en_GB-alan-medium' => [
                'label' => 'Alan',
                'description' => 'British English · male',
                'model' => env('PIPER_VOICE_ALAN_MODEL', '/opt/piper/voices/en_GB-alan-medium.onnx'),
            ],
        ],
    ],
];
