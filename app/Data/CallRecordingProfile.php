<?php

namespace App\Data;

use App\Enums\CallRecordingMediaType;
use App\Enums\CallSessionType;

readonly class CallRecordingProfile
{
    public function __construct(
        public CallSessionType $sessionType,
        public CallRecordingMediaType $mediaType,
        public string $container,
    ) {}
}
