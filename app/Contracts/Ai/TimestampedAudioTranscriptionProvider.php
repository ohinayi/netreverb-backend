<?php

namespace App\Contracts\Ai;

interface TimestampedAudioTranscriptionProvider
{
    /**
     * @return array{text: string, segments: array<int, array{start_ms: int, end_ms: int|null, text: string}>}
     */
    public function transcribe(string $path): array;
}
