<?php

namespace App\Console\Commands;

use App\Models\AiAssistant;
use App\Models\CallLog;
use App\Models\CallRecordingUpload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:diagnose-recordings
    {assistant? : Optional AI assistant public ID; defaults to the latest enabled assistant}')]
#[Description('Show the recordings Laravel can see for an AI assistant organization')]
class DiagnoseAiAssistantRecordings extends Command
{
    public function handle(): int
    {
        $assistant = AiAssistant::query()
            ->with('extension.dialableNumber:id,number')
            ->where('enabled', true)
            ->when($this->argument('assistant'), fn ($query, string $publicId) => $query->where('public_id', $publicId))
            ->latest()
            ->first();

        if ($assistant === null) {
            $this->error('No enabled AI assistant was found. Create or enable one first.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Assistant: %s (%s) | organization #%d | extension: %s',
            $assistant->name,
            $assistant->public_id,
            $assistant->organization_id,
            $assistant->extension?->dialableNumber?->number ?? 'not assigned',
        ));

        $callLogs = CallLog::query()
            ->where('organization_id', $assistant->organization_id)
            ->latest('id')
            ->limit(12)
            ->get();

        $this->newLine();
        $this->line('Latest call logs in this assistant organization:');
        $this->table(
            ['Call', 'Callee ID', 'Recording status', 'File path', 'Recording URL', 'Created'],
            $callLogs->map(fn (CallLog $callLog) => [
                $callLog->public_id,
                $callLog->callee_extension_id ?? '-',
                $callLog->recording_status?->value ?? '-',
                $callLog->recording_file_path ?? '-',
                $callLog->recording_url ? 'yes' : '-',
                $callLog->created_at?->toDateTimeString() ?? '-',
            ])->all(),
        );

        $uploads = CallRecordingUpload::query()
            ->where('organization_id', $assistant->organization_id)
            ->latest('id')
            ->limit(12)
            ->get();

        $this->newLine();
        $this->line('Latest browser recording uploads in this assistant organization:');
        $this->table(
            ['Upload', 'Call log ID', 'Status', 'File path', 'Finalized'],
            $uploads->map(fn (CallRecordingUpload $upload) => [
                $upload->public_id,
                $upload->call_log_id ?? '-',
                $upload->status?->value ?? '-',
                $upload->file_path ?? '-',
                $upload->finalized_at?->toDateTimeString() ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
