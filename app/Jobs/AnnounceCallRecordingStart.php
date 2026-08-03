<?php

namespace App\Jobs;

use App\Models\CallLog;
use App\Services\CallRecordings\CallRecordingManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnnounceCallRecordingStart implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $callLogId,
        public readonly string $callUuid,
    ) {
        $this->onQueue('telephony');
    }

    public function handle(CallRecordingManager $recordingManager): void
    {
        $callLog = CallLog::query()->with('organization')->find($this->callLogId);

        if ($callLog !== null) {
            $recordingManager->announceStart($callLog, $this->callUuid);
        }
    }
}
