<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\CallRecordingStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class RecordingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();
        $recordingFilePath = $attributes['recording_file_path'] ?? null;
        $recordingStatus = $this->recording_status?->value ?? $attributes['recording_status'] ?? null;

        return [
            'call_log_id' => $this->public_id,
            'caller_number' => $this->caller_number,
            'callee_number' => $this->callee_number,
            'caller_extension' => ExtensionResource::make($this->whenLoaded('callerExtension')),
            'callee_extension' => ExtensionResource::make($this->whenLoaded('calleeExtension')),
            'status' => $recordingStatus,
            'media_type' => $this->recording_media_type,
            'duration' => $attributes['recording_duration'] ?? null,
            'size' => $attributes['recording_size'] ?? null,
            'file_name' => $attributes['recording_file_name'] ?? null,
            'playback_available' => $recordingStatus === CallRecordingStatus::Completed->value
                && $recordingFilePath !== null
                && Storage::disk(config('telephony.call_recordings.disk'))->exists($recordingFilePath),
            'url' => route('organizations.call-logs.recording.show', [
                'organization' => $this->organizationPublicId($request),
                'callLog' => $this->public_id,
            ]),
            'started_at' => $this->recording_started_at,
            'ended_at' => $this->recording_ended_at,
            'call_started_at' => $this->started_at,
        ];
    }

    private function organizationPublicId(Request $request): ?string
    {
        $organization = $request->route('organization');

        return is_object($organization) && isset($organization->public_id) ? $organization->public_id : null;
    }
}
