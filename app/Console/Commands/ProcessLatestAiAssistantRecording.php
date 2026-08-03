<?php

namespace App\Console\Commands;

use App\Enums\CallRecordingStatus;
use App\Enums\CallRecordingUploadStatus;
use App\Jobs\ProcessAiAssistantCallRecording;
use App\Models\AiAssistant;
use App\Models\CallLog;
use App\Models\CallRecordingUpload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('ai:process-latest-recording
    {assistant? : Optional AI assistant public ID; defaults to the latest enabled assistant}
    {--sync : Process the recording in this command instead of queueing it}')]
#[Description('Queue the latest completed recording for an enabled AI assistant')]
class ProcessLatestAiAssistantRecording extends Command
{
    public function handle(): int
    {
        $assistant = AiAssistant::query()
            ->where('enabled', true)
            ->when($this->argument('assistant'), fn ($query, string $publicId) => $query->where('public_id', $publicId))
            ->latest()
            ->first();

        if ($assistant === null || $assistant->extension_id === null) {
            $this->error('No enabled AI assistant with an assigned extension was found.');

            return self::FAILURE;
        }

        $assistantCallLogs = CallLog::query()
            ->where('organization_id', $assistant->organization_id)
            ->where('callee_extension_id', $assistant->extension_id)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'freeswitch_uuid', 'recording_uuid']);

        $freeswitchUuids = $assistantCallLogs->pluck('freeswitch_uuid')->filter()->values();
        $recordingUuids = $assistantCallLogs->pluck('recording_uuid')->filter()->values();

        $callLog = CallLog::query()
            ->with('recordingUpload')
            ->where('organization_id', $assistant->organization_id)
            ->where('recording_status', CallRecordingStatus::Completed)
            ->whereNotNull('recording_file_path')
            ->where(function ($query) use ($assistant, $freeswitchUuids, $recordingUuids): void {
                $query->where('callee_extension_id', $assistant->extension_id);

                if ($freeswitchUuids->isNotEmpty()) {
                    $query->orWhereIn('freeswitch_uuid', $freeswitchUuids);
                }

                if ($recordingUuids->isNotEmpty()) {
                    $query->orWhereIn('recording_uuid', $recordingUuids);
                }
            })
            ->latest('recording_ended_at')
            ->first();
        $manualAssistantOverride = false;
        $uploadPath = null;

        if ($callLog !== null && $callLog->callee_extension_id !== $assistant->extension_id) {
            $manualAssistantOverride = true;
        }

        if ($callLog === null) {
            $upload = CallRecordingUpload::query()
                ->where('organization_id', $assistant->organization_id)
                ->where('status', CallRecordingUploadStatus::Completed)
                ->with('callLog')
                ->latest('finalized_at')
                ->first();
            $callLog = $upload?->callLog;
            $uploadPath = $upload?->file_path;
            $manualAssistantOverride = $callLog !== null;
        }

        $recordingPath = $callLog?->recording_file_path ?? $uploadPath;

        if ($callLog === null || blank($recordingPath)) {
            $this->error('No completed recording was found for this assistant or its organization.');

            return self::FAILURE;
        }

        $disk = Storage::disk(config('telephony.call_recordings.disk'));
        if (! $disk->exists($recordingPath)) {
            $this->error('The recording metadata exists, but its local audio file is unavailable: '.$recordingPath);

            return self::FAILURE;
        }

        $job = new ProcessAiAssistantCallRecording(
            $callLog->id,
            $manualAssistantOverride ? $assistant->id : null,
        );

        if ($this->option('sync')) {
            dispatch_sync($job);
        } else {
            dispatch($job);
        }

        $this->info(sprintf(
            '%s %s for assistant %s using recording %s%s.',
            $this->option('sync') ? 'Processed' : 'Queued',
            $callLog->public_id,
            $assistant->public_id,
            $recordingPath,
            $manualAssistantOverride ? ' (organization fallback)' : '',
        ));

        return self::SUCCESS;
    }
}
