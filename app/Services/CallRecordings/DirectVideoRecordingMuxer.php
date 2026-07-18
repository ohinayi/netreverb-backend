<?php

namespace App\Services\CallRecordings;

use App\Models\CallLog;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class DirectVideoRecordingMuxer
{
    public function audioSidecarRelativePath(CallLog $callLog): string
    {
        if ($callLog->recording_file_path === null || $callLog->recording_file_path === '') {
            throw new RuntimeException('A recording file path is required before an audio sidecar can be derived.');
        }

        return $callLog->recording_file_path.'.audio.wav';
    }

    public function audioSidecarAbsolutePath(CallLog $callLog): string
    {
        return rtrim((string) config('telephony.call_recordings.base_path'), '/').'/'.$this->audioSidecarRelativePath($callLog);
    }

    public function audioSidecarExists(CallLog $callLog): bool
    {
        return Storage::disk(config('telephony.call_recordings.disk'))
            ->exists($this->audioSidecarRelativePath($callLog));
    }

    public function muxUploadedVideoWithServerAudio(CallLog $callLog): void
    {
        if ($callLog->recording_file_path === null || $callLog->recording_file_path === '') {
            throw new RuntimeException('A recording file path is required before muxing can begin.');
        }

        $disk = Storage::disk(config('telephony.call_recordings.disk'));
        $videoRelativePath = $callLog->recording_file_path;
        $audioRelativePath = $this->audioSidecarRelativePath($callLog);

        if (! $disk->exists($videoRelativePath)) {
            throw new RuntimeException('The uploaded video file is not available for muxing.');
        }

        if (! $disk->exists($audioRelativePath)) {
            throw new RuntimeException('The server audio sidecar is not available for muxing.');
        }

        $videoAbsolutePath = $disk->path($videoRelativePath);
        $audioAbsolutePath = $disk->path($audioRelativePath);
        $container = (string) $callLog->recording_container;
        $tempRelativePath = $videoRelativePath.'.muxing.'.$container;
        $tempAbsolutePath = $disk->path($tempRelativePath);

        $disk->delete($tempRelativePath);

        $process = new Process($this->muxCommand(
            $videoAbsolutePath,
            $audioAbsolutePath,
            $tempAbsolutePath,
            $container,
        ));
        $process->setTimeout((int) config('telephony.call_recordings.direct_video_mux.timeout_seconds', 180));
        $process->run();

        if (! $process->isSuccessful()) {
            $disk->delete($tempRelativePath);

            throw new RuntimeException(trim($process->getErrorOutput()) !== ''
                ? trim($process->getErrorOutput())
                : 'Video recording mux failed.');
        }

        if (! $disk->exists($tempRelativePath)) {
            throw new RuntimeException('The muxed recording output was not created.');
        }

        $disk->delete($videoRelativePath);
        $disk->move($tempRelativePath, $videoRelativePath);
        $disk->delete($audioRelativePath);
    }

    /**
     * @return list<string>
     */
    private function muxCommand(
        string $videoAbsolutePath,
        string $audioAbsolutePath,
        string $outputAbsolutePath,
        string $container,
    ): array {
        $binary = (string) config('telephony.call_recordings.direct_video_mux.ffmpeg_binary', 'ffmpeg');

        return match ($container) {
            'mp4' => [
                $binary,
                '-y',
                '-i',
                $videoAbsolutePath,
                '-i',
                $audioAbsolutePath,
                '-map',
                '0:v:0',
                '-map',
                '1:a:0',
                '-c:v',
                'copy',
                '-c:a',
                'aac',
                '-b:a',
                '128k',
                '-movflags',
                '+faststart',
                '-shortest',
                $outputAbsolutePath,
            ],
            'webm' => [
                $binary,
                '-y',
                '-i',
                $videoAbsolutePath,
                '-i',
                $audioAbsolutePath,
                '-map',
                '0:v:0',
                '-map',
                '1:a:0',
                '-c:v',
                'copy',
                '-c:a',
                'libopus',
                '-b:a',
                '96k',
                '-shortest',
                $outputAbsolutePath,
            ],
            default => throw new RuntimeException(sprintf('Unsupported recording container for muxing: %s', $container)),
        };
    }
}
