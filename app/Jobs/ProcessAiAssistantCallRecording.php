<?php

namespace App\Jobs;

use App\Contracts\Ai\AudioTranscriptionProvider;
use App\Contracts\Ai\StructuredAssistantProvider;
use App\Models\AiAssistant;
use App\Models\AiAssistantSession;
use App\Models\AiUsageRecord;
use App\Models\CallLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessAiAssistantCallRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public int $callLogId, public ?int $aiAssistantId = null)
    {
        $this->onQueue('ai');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(
        AudioTranscriptionProvider $transcriptionProvider,
        StructuredAssistantProvider $structuredAssistantProvider,
    ): void {
        if (! (bool) config('ai.transcription.enabled')) {
            return;
        }

        $callLog = CallLog::query()->with('recordingUpload')->find($this->callLogId);
        $recordingPath = $callLog?->recording_file_path ?: $callLog?->recordingUpload?->file_path;

        if ($callLog === null || blank($recordingPath)) {
            Log::info('AI assistant recording skipped because the call has no usable completed recording.', [
                'call_log_id' => $this->callLogId,
                'found' => $callLog !== null,
                'callee_extension_id' => $callLog?->callee_extension_id,
                'recording_file_path' => $recordingPath,
            ]);

            return;
        }

        $assistantExtensionId = $callLog->callee_extension_id;

        if ($this->aiAssistantId === null && $assistantExtensionId === null) {
            $assistantExtensionId = $this->pairedAssistantExtensionId($callLog);
        }

        $assistant = AiAssistant::query()
            ->where('organization_id', $callLog->organization_id)
            ->when(
                $this->aiAssistantId !== null,
                fn ($query) => $query->whereKey($this->aiAssistantId),
                fn ($query) => $query->where('extension_id', $assistantExtensionId),
            )
            ->where('enabled', true)
            ->with('fields')
            ->first();

        if ($assistant === null) {
            Log::info('AI assistant recording skipped because no enabled assistant matches the callee extension.', [
                'call_log_id' => $callLog->id,
                'organization_id' => $callLog->organization_id,
                'callee_extension_id' => $callLog->callee_extension_id,
                'paired_callee_extension_id' => $assistantExtensionId,
            ]);

            return;
        }

        $session = AiAssistantSession::query()->firstOrCreate(
            ['ai_assistant_id' => $assistant->id, 'call_log_id' => $callLog->id],
            [
                'organization_id' => $callLog->organization_id,
                'status' => 'transcribing',
                'duration_seconds' => $callLog->recording_duration ?? $callLog->duration,
                'started_at' => $callLog->started_at,
            ],
        );

        if ($session->status === 'completed') {
            return;
        }

        $transcript = $transcriptionProvider->transcribe(
            (string) config('telephony.call_recordings.disk'),
            $recordingPath,
        );
        $session->forceFill(['status' => 'extracting', 'transcript' => $transcript])->save();

        AiUsageRecord::query()->create([
            'organization_id' => $callLog->organization_id,
            'ai_assistant_session_id' => $session->id,
            'provider' => (string) config('ai.transcription.provider'),
            'usage_type' => 'transcription',
            'duration_seconds' => $callLog->recording_duration ?? $callLog->duration,
            'metadata' => ['recording_path' => $recordingPath],
        ]);

        $capturedData = [];
        if ($assistant->fields->isNotEmpty() && filled($assistant->system_instruction) && filled(config('ai.gemini.api_key'))) {
            $capturedData = $structuredAssistantProvider->extract(
                $this->instruction($assistant),
                $transcript,
                $this->schema($assistant),
            );
            AiUsageRecord::query()->create([
                'organization_id' => $callLog->organization_id,
                'ai_assistant_session_id' => $session->id,
                'provider' => 'gemini',
                'usage_type' => 'structured_extraction',
                'input_units' => Str::wordCount($transcript),
                'metadata' => ['model' => config('ai.gemini.model')],
            ]);
        }

        $session->forceFill([
            'status' => 'completed',
            'captured_data' => $capturedData,
            'duration_seconds' => $callLog->recording_duration ?? $callLog->duration,
            'completed_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $session = AiAssistantSession::query()
            ->whereHas('callLog', fn ($query) => $query->whereKey($this->callLogId))
            ->latest('id')
            ->first();

        if ($session !== null) {
            $session->forceFill([
                'status' => 'failed',
                'provider_metadata' => ['error' => $exception?->getMessage()],
            ])->save();
        }

        Log::warning('AI assistant recording processing failed.', [
            'call_log_id' => $this->callLogId,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }

    private function instruction(AiAssistant $assistant): string
    {
        return implode("\n\n", array_filter([
            $assistant->system_instruction,
            'Treat the transcript as the only source of truth. Extract a field only when its value was clearly spoken. Never guess, correct noisy speech, invent a value, or use placeholders such as "unknown". Use null for anything missing, unclear, partial, or uncertain. A summary must contain only facts clearly stated in the transcript; otherwise use null.',
        ]));
    }

    private function pairedAssistantExtensionId(CallLog $recordingCallLog): ?int
    {
        $freeswitchUuid = $recordingCallLog->freeswitch_uuid;
        $recordingUuid = $recordingCallLog->recording_uuid;

        if (blank($freeswitchUuid) && blank($recordingUuid)) {
            return null;
        }

        return CallLog::query()
            ->where('organization_id', $recordingCallLog->organization_id)
            ->whereNotNull('callee_extension_id')
            ->where('id', '!=', $recordingCallLog->id)
            ->where(function ($query) use ($freeswitchUuid, $recordingUuid): void {
                if (filled($freeswitchUuid)) {
                    $query->orWhere('freeswitch_uuid', $freeswitchUuid);
                }

                if (filled($recordingUuid)) {
                    $query->orWhere('recording_uuid', $recordingUuid);
                }
            })
            ->latest('id')
            ->value('callee_extension_id');
    }

    /** @return array<string, mixed> */
    private function schema(AiAssistant $assistant): array
    {
        return [
            'type' => 'object',
            'properties' => $assistant->fields->mapWithKeys(fn ($field): array => [
                $field->key => [
                    'type' => $field->field_type === 'boolean' ? 'boolean' : 'string',
                    'nullable' => true,
                    'description' => $field->question ?: $field->label,
                ],
            ])->all(),
            'required' => $assistant->fields->where('required', true)->pluck('key')->all(),
        ];
    }
}
