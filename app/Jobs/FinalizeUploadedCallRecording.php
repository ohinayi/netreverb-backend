<?php

namespace App\Jobs;

use App\Enums\CallRecordingStatus;
use App\Enums\CallRecordingUploadStatus;
use App\Models\CallLog;
use App\Models\CallRecordingUpload;
use App\Services\CallRecordings\DirectVideoRecordingUploadManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalizeUploadedCallRecording implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $callLogId)
    {
        $this->onQueue('recordings');
    }

    public function uniqueId(): string
    {
        return (string) $this->callLogId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(DirectVideoRecordingUploadManager $uploadManager): void
    {
        $callLog = CallLog::query()->find($this->callLogId);

        if ($callLog === null) {
            return;
        }

        $uploadManager->completeFinalizedUpload($callLog);
        ProcessAiAssistantCallRecording::dispatch($callLog->id)->afterCommit();
    }

    public function failed(?Throwable $exception): void
    {
        $callLog = CallLog::query()->find($this->callLogId);

        if ($callLog !== null) {
            $callLog->forceFill([
                'recording_status' => CallRecordingStatus::Failed,
            ])->save();

            CallRecordingUpload::query()
                ->whereBelongsTo($callLog)
                ->update([
                    'status' => CallRecordingUploadStatus::Failed->value,
                ]);
        }

        Log::warning('Queued call recording upload finalization failed.', [
            'call_log_id' => $callLog?->id,
            'public_id' => $callLog?->public_id,
            'recording_id' => $callLog?->recording_id,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }
}
