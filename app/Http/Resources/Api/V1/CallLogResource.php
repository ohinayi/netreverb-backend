<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CallLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();
        $recordingUrl = $attributes['recording_url'] ?? null;
        $recordingFilePath = $attributes['recording_file_path'] ?? null;

        return [
            'id' => $this->public_id,
            'caller_number' => $this->caller_number,
            'callee_number' => $this->callee_number,
            'status' => $this->status,
            'duration' => $this->duration,
            'freeswitch_uuid' => $attributes['freeswitch_uuid'] ?? null,
            'recording' => $recordingUrl || $recordingFilePath ? [
                'url' => $recordingUrl ?? $this->recordingUrlFor($request),
                'duration' => $attributes['recording_duration'] ?? null,
                'size' => $attributes['recording_size'] ?? null,
                'status' => isset($attributes['recording_status'])
                    ? $this->recording_status?->value ?? $attributes['recording_status']
                    : null,
                'file_name' => $attributes['recording_file_name'] ?? null,
            ] : null,
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'caller_extension' => ExtensionResource::make($this->whenLoaded('callerExtension')),
            'callee_extension' => ExtensionResource::make($this->whenLoaded('calleeExtension')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function recordingUrlFor(Request $request): ?string
    {
        $organization = $request->route('organization');

        if ($organization instanceof Organization) {
            return route('organizations.call-logs.recording.show', [
                'organization' => $organization->public_id,
                'callLog' => $this->public_id,
            ]);
        }

        $organizationPublicId = $this->organization?->public_id
            ?? Organization::query()->whereKey($this->organization_id)->value('public_id');

        if ($organizationPublicId === null) {
            return null;
        }

        return route('organizations.call-logs.recording.show', [
            'organization' => $organizationPublicId,
            'callLog' => $this->public_id,
        ]);
    }
}
