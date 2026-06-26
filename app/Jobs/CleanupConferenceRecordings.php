<?php

namespace App\Jobs;

use App\Services\ConferenceRecordings\ConferenceRecordingManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CleanupConferenceRecordings implements ShouldQueue
{
    use Queueable;

    public function handle(ConferenceRecordingManager $recordingManager): void
    {
        $recordingManager->cleanup((int) config('telephony.recordings.retention_days', 30));
    }
}
