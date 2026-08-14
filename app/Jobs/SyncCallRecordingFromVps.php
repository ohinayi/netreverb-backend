<?php

namespace App\Jobs;

use App\Models\CallLog;
use App\Services\CallRecordings\CallRecordingVpsSynchronizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

class SyncCallRecordingFromVps implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $callLogId, public ?string $relativePath = null)
    {
        $this->onQueue('recordings');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(CallRecordingVpsSynchronizer $synchronizer): void
    {
        if (! (bool) config('telephony.call_recordings.sync.enabled', true)) {
            return;
        }

        $callLog = CallLog::query()->find($this->callLogId);

        if ($callLog === null) {
            return;
        }

        $pathToSync = $this->relativePath;

        if ($pathToSync === null || $pathToSync === '') {
            $pathToSync = $callLog->recording_file_path;
        }

        if ($pathToSync === null || $pathToSync === '') {
            return;
        }

        $relativePath = dirname($pathToSync);
        $normalizedRelativePath = $relativePath === '.' ? null : $relativePath;

        $synchronizer->sync(
            host: (string) config('telephony.call_recordings.sync.host'),
            user: (string) config('telephony.call_recordings.sync.user'),
            remoteBasePath: (string) config('telephony.call_recordings.sync.remote_base'),
            remoteRelativePath: $normalizedRelativePath,
            password: config('telephony.call_recordings.sync.password'),
            dryRun: false,
            output: new NullOutput,
        );

        $callLog->refresh();

        if ($callLog->recording_file_path === null || $callLog->recording_file_path === '') {
            return;
        }

        $disk = Storage::disk(config('telephony.call_recordings.disk'));

        if (! $disk->exists($callLog->recording_file_path)) {
            Log::warning('Call recording sync completed but the local file is still unavailable.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'recording_file_path' => $callLog->recording_file_path,
            ]);

            return;
        }

        $size = $disk->size($callLog->recording_file_path);

        if ($callLog->recording_size !== $size) {
            $callLog->forceFill([
                'recording_size' => $size,
            ])->save();
        }

        $this->deleteSyncedRemoteSource($synchronizer, $pathToSync);

        ProcessAiAssistantCallRecording::dispatch($callLog->id)->afterCommit();
    }

    /**
     * rsync never removes its source - without this, every synced recording
     * sits on the VPS disk twice (once in FreeSWITCH's own recordings
     * folder, once in this synced copy) forever. Safe to run here: this
     * only ever fires after the recording has already been confirmed
     * stopped by FreeSWITCH and successfully synced locally above, and the
     * remote deletion itself additionally requires the source file to be
     * at least 10 minutes old before it will touch it.
     */
    private function deleteSyncedRemoteSource(CallRecordingVpsSynchronizer $synchronizer, string $syncedRelativePath): void
    {
        if (! (bool) config('telephony.call_recordings.sync.remove_source_after_sync', true)) {
            return;
        }

        $remoteBase = rtrim((string) config('telephony.call_recordings.sync.remote_base'), '/');
        $remoteFilePath = $remoteBase.'/'.ltrim($syncedRelativePath, '/');

        $synchronizer->deleteRemoteFileIfStale(
            host: (string) config('telephony.call_recordings.sync.host'),
            user: (string) config('telephony.call_recordings.sync.user'),
            remoteFilePath: $remoteFilePath,
            password: config('telephony.call_recordings.sync.password'),
            minAgeMinutes: 10,
            output: new NullOutput,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $callLog = CallLog::query()->find($this->callLogId);

        Log::warning('Automatic call recording sync from the VPS failed.', [
            'call_log_id' => $callLog?->id,
            'public_id' => $callLog?->public_id,
            'recording_file_path' => $callLog?->recording_file_path,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }
}
