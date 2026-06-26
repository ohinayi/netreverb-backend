<?php

namespace App\Data;

readonly class CallRecordingLocation
{
    public function __construct(
        public string $relativePath,
        public string $fileName,
        public string $absolutePath,
    ) {}
}
