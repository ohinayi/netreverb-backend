<?php

namespace App\Services\CallRecordings;

use App\Contracts\Recordings\CallRecordingStorage;
use App\Contracts\Telephony\FreeSwitchCallGateway;
use App\Enums\CallRecordingStatus;
use App\Jobs\SyncCallRecordingFromVps;
use App\Models\CallLog;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CallRecordingManager
{
    public function __construct(
        private readonly CallRecordingStorage $storage,
        private readonly FreeSwitchCallGateway $gateway,
        private readonly Dispatcher $dispatcher,
    ) {}

    public function start(CallLog $callLog, string $callUuid): ?CallLog
    {
        $shouldStartRecording = false;

        $callLog = DB::transaction(function () use ($callLog, $callUuid, &$shouldStartRecording): ?CallLog {
            $callLog = CallLog::query()
                ->lockForUpdate()
                ->findOrFail($callLog->id);
            $callLog->loadMissing('organization');

            if ($callLog->recording_status === CallRecordingStatus::Starting
                || $callLog->recording_status === CallRecordingStatus::Recording) {
                $shouldStartRecording = $callLog->recording_status === CallRecordingStatus::Starting;

                return $callLog;
            }

            $callLog = $this->prepareLockedCallLog($callLog, $callUuid, now());
            $callLog->forceFill([
                'recording_status' => CallRecordingStatus::Starting,
                'recording_started_at' => $callLog->recording_started_at ?? now(),
                'recording_ended_at' => null,
                'recording_duration' => null,
                'recording_size' => null,
            ])->save();

            $shouldStartRecording = true;

            return $callLog->refresh();
        }, attempts: 3);

        if ($callLog === null || ! $shouldStartRecording) {
            return null;
        }

        try {
            $absolutePath = $this->storage->absolutePath($callLog);
            $this->storage->ensureDirectoryExists($callLog->recording_file_path ?? '');

            Log::info('Starting FreeSWITCH call recording.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'recording_id' => $callLog->recording_id,
                'recording_uuid' => $callUuid,
                'recording_path' => $absolutePath,
            ]);

            $this->gateway->startRecording($callUuid, $absolutePath);
            $callLog->forceFill([
                'recording_status' => CallRecordingStatus::Recording,
            ])->save();
        } catch (Throwable $exception) {
            $callLog->forceFill([
                'recording_status' => CallRecordingStatus::Failed,
            ])->save();

            Log::warning('FreeSWITCH call recording start failed.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'recording_id' => $callLog->recording_id,
                'recording_uuid' => $callUuid,
                'recording_file_path' => $callLog->recording_file_path,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return $callLog->refresh();
    }

    public function stop(CallLog $callLog): ?CallLog
    {
        $callLog = DB::transaction(function () use ($callLog): ?CallLog {
            $callLog = CallLog::query()
                ->lockForUpdate()
                ->findOrFail($callLog->id);
            $callLog->loadMissing('organization');

            if ($callLog->recording_status !== CallRecordingStatus::Starting
                && $callLog->recording_status !== CallRecordingStatus::Recording) {
                return null;
            }

            return $callLog->refresh();
        }, attempts: 3);

        if ($callLog === null || $callLog->recording_uuid === null) {
            return $callLog;
        }

        try {
            $absolutePath = $this->storage->absolutePath($callLog);

            Log::info('Stopping FreeSWITCH call recording.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'recording_id' => $callLog->recording_id,
                'recording_uuid' => $callLog->recording_uuid,
                'recording_path' => $absolutePath,
            ]);

            $this->gateway->stopRecording($callLog->recording_uuid, $absolutePath);
            $startedAt = $callLog->recording_started_at ?? $callLog->created_at ?? now();
            $endedAt = now();
            $recordingExistsLocally = $this->storage->exists($callLog);

            $callLog->forceFill([
                'recording_status' => CallRecordingStatus::Completed,
                'recording_ended_at' => $endedAt,
                'recording_duration' => max(0, $startedAt->diffInSeconds($endedAt)),
                'recording_size' => $recordingExistsLocally ? $this->storage->size($callLog) : null,
            ])->save();

            if (! $recordingExistsLocally) {
                Log::warning('Call recording completed before the file became available on the application disk.', [
                    'call_log_id' => $callLog->id,
                    'public_id' => $callLog->public_id,
                    'recording_id' => $callLog->recording_id,
                    'recording_uuid' => $callLog->recording_uuid,
                    'recording_file_path' => $callLog->recording_file_path,
                ]);
            }

            if ($callLog->recording_file_path !== null && $callLog->recording_file_path !== '') {
                $this->dispatcher->dispatchAfterResponse(new SyncCallRecordingFromVps($callLog->id));
            }
        } catch (Throwable $exception) {
            $callLog->forceFill([
                'recording_status' => CallRecordingStatus::Orphaned,
            ])->save();

            Log::warning('FreeSWITCH call recording stop failed.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'recording_id' => $callLog->recording_id,
                'recording_uuid' => $callLog->recording_uuid,
                'recording_file_path' => $callLog->recording_file_path,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return $callLog->refresh();
    }

    public function delete(CallLog $callLog): void
    {
        $this->storage->delete($callLog);
        $callLog->forceFill([
            'recording_status' => CallRecordingStatus::Orphaned,
            'recording_id' => null,
            'recording_file_path' => null,
            'recording_file_name' => null,
            'recording_duration' => null,
            'recording_size' => null,
            'recording_url' => null,
            'recording_uuid' => null,
            'recording_started_at' => null,
            'recording_ended_at' => null,
        ])->save();
    }

    public function cleanup(int $retentionDays): int
    {
        $cutoff = now()->subDays(max(1, $retentionDays));
        $deletedCount = 0;

        CallLog::query()
            ->whereNotNull('recording_file_path')
            ->whereNull('deleted_at')
            ->oldest('id')
            ->chunkById(100, function ($callLogs) use ($cutoff, &$deletedCount): void {
                foreach ($callLogs as $callLog) {
                    $shouldDelete = false;

                    if (! $this->storage->exists($callLog)) {
                        $shouldDelete = true;
                    } elseif (in_array($callLog->recording_status, [
                        CallRecordingStatus::Failed,
                        CallRecordingStatus::Orphaned,
                    ], true)
                        && $callLog->created_at !== null
                        && $callLog->created_at->lt($cutoff)) {
                        $shouldDelete = true;
                    } elseif ($callLog->recording_status === CallRecordingStatus::Completed
                        && $callLog->created_at !== null
                        && $callLog->created_at->lt($cutoff)) {
                        $shouldDelete = true;
                    }

                    if (! $shouldDelete) {
                        continue;
                    }

                    $this->delete($callLog);
                    $deletedCount++;
                }
            });

        return $deletedCount;
    }

    public function reconcileMissingFiles(): int
    {
        $reconciledCount = 0;

        CallLog::query()
            ->whereNotNull('recording_file_path')
            ->whereNull('deleted_at')
            ->oldest('id')
            ->chunkById(100, function ($callLogs) use (&$reconciledCount): void {
                foreach ($callLogs as $callLog) {
                    if ($this->storage->exists($callLog)) {
                        continue;
                    }

                    $this->delete($callLog);
                    $reconciledCount++;
                }
            });

        return $reconciledCount;
    }

    public function prepare(CallLog $callLog, string $callUuid): CallLog
    {
        return DB::transaction(function () use ($callLog, $callUuid): CallLog {
            $lockedCallLog = CallLog::query()
                ->lockForUpdate()
                ->findOrFail($callLog->id);
            $lockedCallLog->loadMissing('organization');

            if ($this->hasPreparedRecordingMetadata($lockedCallLog, $callUuid)) {
                return $lockedCallLog->refresh();
            }

            $preparedCallLog = $this->prepareLockedCallLog($lockedCallLog, $callUuid, now());
            $preparedCallLog->save();
            $this->storage->ensureDirectoryExists($preparedCallLog->recording_file_path ?? '');

            return $preparedCallLog->refresh();
        }, attempts: 3);
    }

    private function prepareLockedCallLog(CallLog $callLog, string $callUuid, Carbon $recordedAt): CallLog
    {
        $location = $this->storage->buildLocation($callLog, $callUuid, $recordedAt);

        $callLog->forceFill([
            'recording_id' => $callLog->recording_id ?: (string) Str::ulid(),
            'recording_uuid' => $callUuid,
            'recording_file_path' => $location->relativePath,
            'recording_file_name' => $location->fileName,
            'recording_url' => route('organizations.call-logs.recording.show', [
                'organization' => $callLog->organization?->public_id ?? $callLog->organization_id,
                'callLog' => $callLog->public_id,
            ]),
            'recording_started_at' => $callLog->recording_started_at ?? $recordedAt,
        ]);

        Log::info('Call recording metadata prepared.', [
            'call_log_id' => $callLog->id,
            'public_id' => $callLog->public_id,
            'recording_id' => $callLog->recording_id,
            'recording_uuid' => $callUuid,
            'recording_file_path' => $location->relativePath,
            'recording_file_name' => $location->fileName,
        ]);

        return $callLog;
    }

    private function hasPreparedRecordingMetadata(CallLog $callLog, string $callUuid): bool
    {
        return $callLog->recording_uuid === $callUuid
            && $callLog->recording_file_path !== null
            && $callLog->recording_file_path !== ''
            && $callLog->recording_file_name !== null
            && $callLog->recording_file_name !== '';
    }
}
