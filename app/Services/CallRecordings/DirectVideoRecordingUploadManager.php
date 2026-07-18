<?php

namespace App\Services\CallRecordings;

use App\Enums\CallRecordingStatus;
use App\Enums\CallRecordingUploadStatus;
use App\Jobs\FinalizeUploadedCallRecording;
use App\Models\CallLog;
use App\Models\CallRecordingUpload;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DirectVideoRecordingUploadManager
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly CallRecordingVpsSynchronizer $synchronizer,
        private readonly DirectVideoRecordingMuxer $muxer,
    ) {}

    public function createOrRefreshSession(
        CallLog $callLog,
        string $container,
        ?string $mimeType = null,
    ): CallRecordingUpload {
        return DB::transaction(function () use ($callLog, $container, $mimeType): CallRecordingUpload {
            $callLog = CallLog::query()->lockForUpdate()->findOrFail($callLog->id);

            /** @var CallRecordingUpload|null $upload */
            $upload = CallRecordingUpload::query()
                ->whereBelongsTo($callLog)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'organization_id' => $callLog->organization_id,
                'recording_id' => $callLog->recording_id ?? (string) Str::ulid(),
                'status' => $upload?->status ?? CallRecordingUploadStatus::Pending,
                'media_type' => $callLog->recording_media_type,
                'container' => $container,
                'mime_type' => $mimeType,
                'file_path' => $callLog->recording_file_path,
                'file_name' => $callLog->recording_file_name,
            ];

            if ($upload === null) {
                $upload = CallRecordingUpload::query()->create([
                    'call_log_id' => $callLog->id,
                    ...$attributes,
                ]);
            } else {
                $upload->forceFill($attributes)->save();
            }

            return $upload->refresh();
        }, attempts: 3);
    }

    public function appendChunk(CallLog $callLog, int $sequence, UploadedFile $chunk): CallRecordingUpload
    {
        return DB::transaction(function () use ($callLog, $sequence, $chunk): CallRecordingUpload {
            $upload = CallRecordingUpload::query()
                ->whereBelongsTo($callLog)
                ->lockForUpdate()
                ->first();

            if ($upload === null) {
                throw ValidationException::withMessages([
                    'chunk' => 'A client video upload session has not been initialized for this call recording.',
                ]);
            }

            if (in_array($upload->status, [
                CallRecordingUploadStatus::Finalizing,
                CallRecordingUploadStatus::Completed,
            ], true)) {
                throw ValidationException::withMessages([
                    'chunk' => 'This call recording upload can no longer accept new chunks.',
                ]);
            }

            if ($sequence < $upload->next_sequence) {
                return $upload->refresh();
            }

            if ($sequence > $upload->next_sequence) {
                throw new ConflictHttpException('This chunk arrived out of order. Retry from the next expected sequence.');
            }

            $this->appendChunkToRecordingFile($upload, $chunk);

            $upload->forceFill([
                'status' => CallRecordingUploadStatus::Uploading,
                'next_sequence' => $upload->next_sequence + 1,
                'uploaded_chunks_count' => $upload->uploaded_chunks_count + 1,
                'uploaded_size' => $upload->uploaded_size + max(0, (int) $chunk->getSize()),
                'upload_started_at' => $upload->upload_started_at ?? now(),
                'last_chunk_received_at' => now(),
            ])->save();

            logger()->info('Direct video recording chunk appended.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'recording_id' => $callLog->recording_id,
                'upload_id' => $upload->public_id,
                'sequence' => $sequence,
                'next_sequence' => $upload->next_sequence,
                'uploaded_chunks_count' => $upload->uploaded_chunks_count,
                'uploaded_size' => $upload->uploaded_size,
            ]);

            return $upload->refresh();
        }, attempts: 3);
    }

    public function finalize(CallLog $callLog, ?CarbonInterface $endedAt = null): CallRecordingUpload
    {
        $endedAt ??= now();

        return DB::transaction(function () use ($callLog, $endedAt): CallRecordingUpload {
            $callLog = CallLog::query()->lockForUpdate()->findOrFail($callLog->id);
            $upload = CallRecordingUpload::query()
                ->whereBelongsTo($callLog)
                ->lockForUpdate()
                ->first();

            if ($upload === null) {
                throw ValidationException::withMessages([
                    'recording' => 'No client video upload session exists for this call log.',
                ]);
            }

            if ($upload->uploaded_chunks_count === 0) {
                throw ValidationException::withMessages([
                    'recording' => 'At least one uploaded chunk is required before finalization.',
                ]);
            }

            if ($upload->status === CallRecordingUploadStatus::Completed) {
                return $upload->refresh();
            }

            $upload->forceFill([
                'status' => CallRecordingUploadStatus::Finalizing,
                'upload_completed_at' => now(),
            ])->save();

            $startedAt = $callLog->recording_started_at ?? $callLog->created_at ?? $endedAt;

            $callLog->forceFill([
                'recording_status' => CallRecordingStatus::Processing,
                'recording_ended_at' => $endedAt,
                'recording_duration' => max(0, $startedAt->diffInSeconds($endedAt)),
                'recording_size' => $upload->uploaded_size,
            ])->save();

            logger()->info('Direct video recording upload finalization queued.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'recording_id' => $callLog->recording_id,
                'upload_id' => $upload->public_id,
                'uploaded_chunks_count' => $upload->uploaded_chunks_count,
                'uploaded_size' => $upload->uploaded_size,
            ]);

            $this->dispatcher->dispatch(new FinalizeUploadedCallRecording($callLog->id));

            return $upload->refresh();
        }, attempts: 3);
    }

    public function completeFinalizedUpload(CallLog $callLog): void
    {
        $callLog = CallLog::query()->findOrFail($callLog->id);
        $upload = CallRecordingUpload::query()->whereBelongsTo($callLog)->first();

        if ($upload === null) {
            return;
        }

        $disk = Storage::disk(config('telephony.call_recordings.disk'));
        $this->syncAudioSidecarIfNeeded($callLog);
        $fileExists = $callLog->recording_file_path !== null
            && $callLog->recording_file_path !== ''
            && $disk->exists($callLog->recording_file_path);

        if (! $fileExists) {
            DB::transaction(function () use ($callLog): void {
                $lockedCallLog = CallLog::query()->lockForUpdate()->findOrFail($callLog->id);
                $lockedUpload = CallRecordingUpload::query()->whereBelongsTo($lockedCallLog)->lockForUpdate()->first();

                if ($lockedUpload === null) {
                    return;
                }

                $lockedUpload->forceFill([
                    'status' => CallRecordingUploadStatus::Failed,
                ])->save();

                $lockedCallLog->forceFill([
                    'recording_status' => CallRecordingStatus::Failed,
                ])->save();
            }, attempts: 3);

            return;
        }

        if ($callLog->recording_container !== null
            && $callLog->recording_media_type?->value === 'video'
            && $this->muxer->audioSidecarExists($callLog)) {
            $this->muxer->muxUploadedVideoWithServerAudio($callLog);
        } elseif ($callLog->recording_media_type?->value === 'video') {
            throw new RuntimeException('The direct video recording audio sidecar is not available yet.');
        }

        $recordingSize = $callLog->recording_file_path !== null && $callLog->recording_file_path !== ''
            ? $disk->size($callLog->recording_file_path)
            : null;

        DB::transaction(function () use ($callLog, $recordingSize): void {
            $lockedCallLog = CallLog::query()->lockForUpdate()->findOrFail($callLog->id);
            $lockedUpload = CallRecordingUpload::query()->whereBelongsTo($lockedCallLog)->lockForUpdate()->first();

            if ($lockedUpload === null) {
                return;
            }

            $lockedUpload->forceFill([
                'status' => CallRecordingUploadStatus::Completed,
                'finalized_at' => now(),
            ])->save();

            $lockedCallLog->forceFill([
                'recording_status' => CallRecordingStatus::Completed,
                'recording_size' => $recordingSize,
            ])->save();

            logger()->info('Direct video recording upload finalized.', [
                'call_log_id' => $lockedCallLog->id,
                'public_id' => $lockedCallLog->public_id,
                'recording_id' => $lockedCallLog->recording_id,
                'upload_id' => $lockedUpload->public_id,
                'recording_file_path' => $lockedCallLog->recording_file_path,
                'recording_size' => $lockedCallLog->recording_size,
            ]);
        }, attempts: 3);
    }

    private function syncAudioSidecarIfNeeded(CallLog $callLog): void
    {
        if (! (bool) config('telephony.call_recordings.sync.enabled', true)) {
            return;
        }

        if ($this->muxer->audioSidecarExists($callLog)) {
            return;
        }

        $relativePath = dirname($this->muxer->audioSidecarRelativePath($callLog));
        $normalizedRelativePath = $relativePath === '.' ? null : $relativePath;

        $this->synchronizer->sync(
            host: (string) config('telephony.call_recordings.sync.host'),
            user: (string) config('telephony.call_recordings.sync.user'),
            remoteBasePath: (string) config('telephony.call_recordings.sync.remote_base'),
            remoteRelativePath: $normalizedRelativePath,
            password: config('telephony.call_recordings.sync.password'),
            dryRun: false,
            output: new NullOutput,
        );
    }

    public function delete(CallLog $callLog): void
    {
        CallRecordingUpload::query()->whereBelongsTo($callLog)->delete();
    }

    private function appendChunkToRecordingFile(CallRecordingUpload $upload, UploadedFile $chunk): void
    {
        $disk = Storage::disk(config('telephony.call_recordings.disk'));
        $directory = trim(dirname((string) $upload->file_path), './');

        if ($directory !== '') {
            $disk->makeDirectory($directory);
        }

        $targetPath = $disk->path($upload->file_path);
        $chunkHandle = fopen($chunk->getRealPath(), 'rb');
        $targetHandle = fopen($targetPath, 'ab');

        if ($chunkHandle === false || $targetHandle === false) {
            if (is_resource($chunkHandle)) {
                fclose($chunkHandle);
            }

            if (is_resource($targetHandle)) {
                fclose($targetHandle);
            }

            throw ValidationException::withMessages([
                'chunk' => 'The uploaded recording chunk could not be written to storage.',
            ]);
        }

        try {
            stream_copy_to_stream($chunkHandle, $targetHandle);
        } finally {
            fclose($chunkHandle);
            fclose($targetHandle);
        }
    }
}
