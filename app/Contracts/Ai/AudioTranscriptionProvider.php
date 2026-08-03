<?php

namespace App\Contracts\Ai;

interface AudioTranscriptionProvider
{
    public function transcribe(string $disk, string $path): string;
}
