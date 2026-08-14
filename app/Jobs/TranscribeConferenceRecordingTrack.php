<?php

namespace App\Jobs;

use App\Contracts\Ai\TimestampedAudioTranscriptionProvider;
use App\Enums\ConferenceRecordingTrackStatus;
use App\Enums\ConferenceTranscriptStatus;
use App\Models\ConferenceRecordingTrack;
use App\Services\ConferenceRecordings\ConferenceTranscriptManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class TranscribeConferenceRecordingTrack implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $trackId)
    {
        $this->onQueue('ai');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [20, 90, 240];
    }

    public function handle(
        TimestampedAudioTranscriptionProvider $transcriptionProvider,
        ConferenceTranscriptManager $transcriptManager,
    ): void {
        $track = ConferenceRecordingTrack::query()
            ->with(['conferenceRecording.conferenceRoom', 'conferenceRecording.tracks.transcriptSegments'])
            ->find($this->trackId);

        if ($track === null || $track->conferenceRecording === null || ! $track->conferenceRecording->transcription_enabled) {
            return;
        }

        if (! $transcriptManager->trackIsTranscribable($track)) {
            return;
        }

        if ($track->status !== ConferenceRecordingTrackStatus::Completed) {
            return;
        }

        if ($track->transcript_status === ConferenceTranscriptStatus::Ready) {
            $transcriptManager->finalizeIfReady($track->conferenceRecording);

            return;
        }

        $track->forceFill([
            'transcript_status' => ConferenceTranscriptStatus::Processing,
            'transcript_error' => null,
            'transcript_started_at' => now(),
        ])->save();

        $workDir = storage_path('app/tmp/conference-transcript/'.$track->conferenceRecording->recording_id.'/track-'.$track->id);
        File::ensureDirectoryExists($workDir);
        $inputPath = $workDir.'/input.'.(pathinfo($track->storage_key, PATHINFO_EXTENSION) ?: 'ogg');
        $normalizedPath = $workDir.'/normalized.wav';

        try {
            $inputStream = Storage::disk('livekit_recordings')->readStream($track->storage_key);
            if (! is_resource($inputStream)) {
                throw new \RuntimeException('The transcript source file could not be opened.');
            }

            try {
                File::put($inputPath, stream_get_contents($inputStream) ?: '');
            } finally {
                fclose($inputStream);
            }

            $result = Process::timeout(600)->run([
                'ffmpeg',
                '-y',
                '-i',
                $inputPath,
                '-ac',
                '1',
                '-ar',
                '16000',
                '-vn',
                $normalizedPath,
            ]);

            if (! $result->successful()) {
                throw new \RuntimeException('ffmpeg transcription preprocessing failed: '.$result->errorOutput());
            }

            $transcript = $transcriptionProvider->transcribe($normalizedPath);
            $transcriptManager->storeTrackSegments($track, $transcript['segments']);

            $track->forceFill([
                'transcript_status' => ConferenceTranscriptStatus::Ready,
                'transcript_error' => null,
                'transcript_completed_at' => now(),
            ])->save();

            $transcriptManager->finalizeIfReady($track->conferenceRecording->fresh(['tracks.transcriptSegments', 'conferenceRoom.hostUser']));
        } catch (Throwable $exception) {
            $track->forceFill([
                'transcript_status' => ConferenceTranscriptStatus::Failed,
                'transcript_error' => Str::limit($exception->getMessage(), 500),
                'transcript_completed_at' => now(),
            ])->save();

            $track->conferenceRecording->forceFill([
                'transcript_status' => ConferenceTranscriptStatus::Failed,
                'transcript_error' => 'One or more participant tracks failed transcription.',
                'transcript_completed_at' => now(),
            ])->save();

            Log::warning('Conference recording track transcription failed.', [
                'recording_id' => $track->conferenceRecording->recording_id,
                'track_id' => $track->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        } finally {
            File::deleteDirectory($workDir);
        }
    }
}
