<?php

namespace App\Jobs;

use App\Services\CallRecordings\CallRecordingManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CleanupCallRecordings implements ShouldQueue
{
    use Queueable;

    public function handle(CallRecordingManager $recordingManager): void
    {
        $recordingManager->cleanup((int) config('telephony.call_recordings.retention_days', 30));
    }
}
