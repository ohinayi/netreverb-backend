<?php

namespace App\Services\Ai;

use App\Contracts\Ai\TimestampedAudioTranscriptionProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class WhisperCppTimestampedAudioTranscriptionProvider implements TimestampedAudioTranscriptionProvider
{
    public function __construct(private readonly HttpFactory $http) {}

    public function transcribe(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('The recording could not be opened for transcription.');
        }

        $stream = fopen($path, 'rb');
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

        $payload = $response->json();
        $text = $payload['text'] ?? null;
        $segments = $payload['segments'] ?? [];

        if (! is_string($text)) {
            throw new RuntimeException('The transcription provider returned an invalid response.');
        }

        if (! is_array($segments)) {
            $segments = [];
        }

        $normalizedSegments = [];
        foreach ($segments as $segment) {
            if (! is_array($segment) || ! isset($segment['text']) || ! is_string($segment['text'])) {
                continue;
            }

            $start = isset($segment['start']) ? (float) $segment['start'] : 0.0;
            $end = isset($segment['end']) ? (float) $segment['end'] : null;

            $normalizedSegments[] = [
                'start_ms' => (int) round(max(0, $start) * 1000),
                'end_ms' => $end !== null ? (int) round(max(0, $end) * 1000) : null,
                'text' => trim($segment['text']),
            ];
        }

        if ($normalizedSegments === [] && trim($text) !== '') {
            $normalizedSegments[] = [
                'start_ms' => 0,
                'end_ms' => null,
                'text' => trim($text),
            ];
        }

        return [
            'text' => trim($text),
            'segments' => $normalizedSegments,
        ];
    }
}
