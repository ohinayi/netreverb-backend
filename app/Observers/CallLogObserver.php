<?php

namespace App\Observers;

use App\Enums\CallRecordingStatus;
use App\Jobs\ProcessAiAssistantCallRecording;
use App\Models\CallLog;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class CallLogObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(CallLog $callLog): void
    {
        if ($callLog->wasChanged('recording_status')
            && $callLog->recording_status === CallRecordingStatus::Completed) {
            ProcessAiAssistantCallRecording::dispatch($callLog->id);
        }
    }
}
