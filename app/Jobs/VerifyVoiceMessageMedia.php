<?php

namespace App\Jobs;

use App\Enums\MessageType;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Re-derives a voice message's duration and waveform from the stored audio
 * file via ffmpeg/ffprobe, rather than trusting the client-supplied values
 * used to render the composer/bubble immediately after upload.
 */
class VerifyVoiceMessageMedia implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $messagePublicId)
    {
        $this->onQueue('media');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(): void
    {
        $message = Message::query()->where('public_id', $this->messagePublicId)->first();

        if ($message === null || $message->type !== MessageType::VoiceNote || blank($message->attachment_path)) {
            return;
        }

        $disk = Storage::disk(config('messaging.voice_messages.disk'));

        if (! $disk->exists($message->attachment_path)) {
            Log::warning('Voice message audio file is missing; skipping media verification.', [
                'message_id' => $message->public_id,
            ]);

            return;
        }

        $workDir = storage_path('app/tmp/voice-messages/'.$message->public_id);
        File::ensureDirectoryExists($workDir);
        $extension = pathinfo($message->attachment_path, PATHINFO_EXTENSION) ?: 'webm';
        $inputPath = $workDir.'/input.'.$extension;

        try {
            File::put($inputPath, $disk->get($message->attachment_path));

            $metadata = $message->metadata ?? [];
            $durationMs = $this->probeDurationMs($inputPath);
            $waveform = $this->extractWaveform($inputPath, $workDir);

            if ($durationMs !== null) {
                $metadata['duration_ms'] = $durationMs;
            }
            if ($waveform !== null) {
                $metadata['waveform'] = $waveform;
            }
            $metadata['duration_verified'] = $durationMs !== null;

            $message->forceFill(['metadata' => $metadata])->save();
        } catch (Throwable $exception) {
            Log::warning('Voice message media verification failed.', [
                'message_id' => $message->public_id,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function probeDurationMs(string $inputPath): ?int
    {
        $result = Process::timeout(30)->run([
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $inputPath,
        ]);

        if (! $result->successful()) {
            return null;
        }

        $seconds = (float) trim($result->output());

        return $seconds > 0 ? (int) round($seconds * 1000) : null;
    }

    /** @return list<float>|null */
    private function extractWaveform(string $inputPath, string $workDir): ?array
    {
        $pcmPath = $workDir.'/audio.pcm';

        $result = Process::timeout(30)->run([
            'ffmpeg',
            '-y',
            '-i', $inputPath,
            '-ac', '1',
            '-ar', '8000',
            '-f', 's16le',
            $pcmPath,
        ]);

        if (! $result->successful() || ! File::exists($pcmPath)) {
            return null;
        }

        $samples = File::get($pcmPath);
        $sampleCount = intdiv(strlen($samples), 2);

        if ($sampleCount === 0) {
            return null;
        }

        $buckets = 48;
        $samplesPerBucket = max(1, intdiv($sampleCount, $buckets));
        $peaks = [];

        for ($bucket = 0; $bucket < $buckets; $bucket++) {
            $start = $bucket * $samplesPerBucket;
            if ($start >= $sampleCount) {
                $peaks[] = 0;

                continue;
            }

            $end = min($sampleCount, $start + $samplesPerBucket);
            $peak = 0;

            for ($sample = $start; $sample < $end; $sample++) {
                /** @var array{1: int} $unpacked */
                $unpacked = unpack('s', substr($samples, $sample * 2, 2)) ?: [1 => 0];
                $peak = max($peak, abs($unpacked[1]));
            }

            $peaks[] = $peak;
        }

        $max = max($peaks) ?: 1;

        return array_map(static fn (int $peak): float => round($peak / $max, 3), $peaks);
    }
}
