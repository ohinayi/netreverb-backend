<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AudioTranscriptionProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class WhisperCppTranscriptionProvider implements AudioTranscriptionProvider
{
    public function __construct(private readonly HttpFactory $http) {}

    public function transcribe(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('The recording could not be opened for transcription.');
        }

        try {
            $response = $this->http
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(180)
                ->retry([500, 1500], throw: false)
                ->attach('file', $stream, basename($path))
                ->post(rtrim((string) config('ai.transcription.whisper_cpp_url'), '/').'/inference', [
                    'response_format' => 'json',
                    'temperature' => '0.0',
                ])
                ->throw();
        } finally {
            fclose($stream);
        }

        $text = $response->json('text');

        if (! is_string($text)) {
            throw new RuntimeException('The transcription provider returned an invalid response.');
        }

        return trim($text);
    }
}
