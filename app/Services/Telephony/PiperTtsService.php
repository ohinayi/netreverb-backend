<?php

namespace App\Services\Telephony;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class PiperTtsService
{
    public function generate(string $text, string $relativePath, ?string $voice = null, ?float $speed = null): ?string
    {
        if (config('tts.driver') !== 'piper' || trim($text) === '') {
            return null;
        }

        $binary = (string) config('tts.piper.binary');
        $voiceConfig = $voice ? config('tts.piper.voices.'.$voice, []) : [];
        $model = (string) ($voiceConfig['model'] ?? config('tts.piper.model'));
        $diskName = (string) config('tts.piper.output_disk', 'public');
        if (! is_executable($binary) || ! is_file($model)) {
            return null;
        }

        $disk = Storage::disk($diskName);
        $disk->makeDirectory(dirname($relativePath));
        $output = $disk->path($relativePath);
        $arguments = [$binary, '--model', $model];
        // Piper's --length_scale is inverted from a "speed" dial: lower means
        // faster speech, higher means slower. 1.0 is the model's natural pace.
        $lengthScale = (string) ($speed ?? config('tts.piper.length_scale', '1.0'));
        if (is_numeric($lengthScale) && (float) $lengthScale > 0) {
            $arguments = [...$arguments, '--length_scale', $lengthScale];
        }
        $arguments = [...$arguments, '--output_file', $output];
        $process = new Process($arguments);
        $process->setInput($text);
        $process->setTimeout(60);
        $process->run();
        if (! $process->isSuccessful() || ! is_file($output) || filesize($output) === 0) {
            logger()->warning('Piper IVR synthesis failed.', [
                'error' => trim($process->getErrorOutput()),
                'path' => $relativePath,
            ]);
            return null;
        }

        return $relativePath;
    }
}
