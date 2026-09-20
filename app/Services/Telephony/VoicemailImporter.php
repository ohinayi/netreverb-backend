<?php

namespace App\Services\Telephony;

use App\Models\Extension;
use App\Models\Voicemail;
use Illuminate\Support\Facades\Storage;

class VoicemailImporter
{
    /**
     * Scans the voicemail disk for files FreeSWITCH's `record` action wrote
     * that don't have a Voicemail row yet. Files live at
     * `{extension_public_id}/{timestamp}_{caller_number}.wav` - see
     * FreeSwitchDialplanController::appendVoicemailRecording().
     */
    public function importNew(): int
    {
        $disk = Storage::disk((string) config('telephony.voicemail.disk'));
        $imported = 0;

        foreach ($disk->allFiles() as $path) {
            if (! str_ends_with($path, '.wav')) {
                continue;
            }

            if (Voicemail::query()->where('file_path', $path)->exists()) {
                continue;
            }

            $segments = explode('/', $path);
            if (count($segments) < 2) {
                continue;
            }

            $extensionPublicId = $segments[count($segments) - 2];
            $filename = pathinfo($path, PATHINFO_FILENAME);
            [$timestamp, $callerNumber] = array_pad(explode('_', $filename, 2), 2, '');

            $extension = Extension::query()->where('public_id', $extensionPublicId)->first();
            if (! $extension) {
                continue;
            }

            $absolutePath = $disk->path($path);
            $sizeBytes = $disk->size($path);
            // Skip files still being written - FreeSWITCH's `record` only
            // finalizes the header/size once the call actually ends, so a
            // file mid-recording would otherwise get imported with a
            // truncated/zero duration.
            if ($sizeBytes < 44 || $this->isRecentlyModified($disk, $path)) {
                continue;
            }

            Voicemail::query()->create([
                'organization_id' => $extension->organization_id,
                'extension_id' => $extension->id,
                'caller_number' => $callerNumber !== '' ? $callerNumber : 'unknown',
                'file_path' => $path,
                'duration_seconds' => $this->wavDurationSeconds($absolutePath),
            ]);

            $imported++;
        }

        return $imported;
    }

    private function isRecentlyModified(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path): bool
    {
        return (time() - $disk->lastModified($path)) < 5;
    }

    /**
     * Reads a standard RIFF/WAV header to compute duration without an
     * external binary (ffprobe/soxi) - FreeSWITCH's own `record` application
     * always writes canonical PCM WAV.
     */
    private function wavDurationSeconds(string $absolutePath): int
    {
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            return 0;
        }

        try {
            $header = fread($handle, 12);
            if ($header === false || strlen($header) < 12 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
                return 0;
            }

            $sampleRate = null;
            $channels = null;
            $bitsPerSample = null;
            $dataBytes = null;

            while (! feof($handle)) {
                $chunkHeader = fread($handle, 8);
                if ($chunkHeader === false || strlen($chunkHeader) < 8) {
                    break;
                }

                $chunkId = substr($chunkHeader, 0, 4);
                $chunkSize = unpack('V', substr($chunkHeader, 4, 4))[1] ?? 0;

                if ($chunkId === 'fmt ') {
                    $fmt = fread($handle, $chunkSize);
                    $channels = unpack('v', substr($fmt, 2, 2))[1] ?? null;
                    $sampleRate = unpack('V', substr($fmt, 4, 4))[1] ?? null;
                    $bitsPerSample = unpack('v', substr($fmt, 14, 2))[1] ?? null;
                } elseif ($chunkId === 'data') {
                    $dataBytes = $chunkSize;
                    break;
                } else {
                    fseek($handle, $chunkSize, SEEK_CUR);
                }
            }

            if (! $sampleRate || ! $channels || ! $bitsPerSample || ! $dataBytes) {
                return 0;
            }

            $bytesPerSecond = $sampleRate * $channels * ($bitsPerSample / 8);

            return $bytesPerSecond > 0 ? (int) round($dataBytes / $bytesPerSecond) : 0;
        } finally {
            fclose($handle);
        }
    }
}
