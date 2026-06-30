<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\CallRecordingStatus;
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
        $recordingStatus = isset($attributes['recording_status'])
            ? $this->recording_status?->value ?? $attributes['recording_status']
            : null;
        $playbackAvailable = $this->recordingPlaybackAvailable($recordingStatus, $recordingUrl, $recordingFilePath);
        $viewerPerspective = $this->viewerPerspective($request);

        return [
            'id' => $this->public_id,
            'caller_number' => $this->caller_number,
            'callee_number' => $this->callee_number,
            'status' => $this->status,
            'direction' => $viewerPerspective['direction'],
            'party_status' => $viewerPerspective['party_status'],
            'is_missed' => $viewerPerspective['party_status'] === 'missed',
            'is_answered' => $viewerPerspective['party_status'] === 'answered',
            'duration' => $this->duration,
            'freeswitch_uuid' => $attributes['freeswitch_uuid'] ?? null,
            'recording' => $recordingUrl || $recordingFilePath ? [
                'url' => $recordingUrl ?? $this->recordingUrlFor($request),
                'duration' => $attributes['recording_duration'] ?? null,
                'size' => $attributes['recording_size'] ?? null,
                'status' => $recordingStatus,
                'file_name' => $attributes['recording_file_name'] ?? null,
                'playback_available' => $playbackAvailable,
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

    private function recordingPlaybackAvailable(
        ?string $recordingStatus,
        ?string $recordingUrl,
        ?string $recordingFilePath,
    ): bool {
        return $recordingStatus === CallRecordingStatus::Completed->value
            && (($recordingUrl !== null && $recordingUrl !== '') || ($recordingFilePath !== null && $recordingFilePath !== ''));
    }

    /**
     * @return array{direction: string, party_status: string}
     */
    private function viewerPerspective(Request $request): array
    {
        $callerUserId = $this->callerExtension?->user_id;
        $calleeUserId = $this->calleeExtension?->user_id;
        $viewerUserId = $request->user()?->getKey();

        $direction = 'external';
        $viewerIsCaller = $viewerUserId !== null && $callerUserId === $viewerUserId;
        $viewerIsCallee = $viewerUserId !== null && $calleeUserId === $viewerUserId;

        if ($viewerIsCaller && $viewerIsCallee) {
            $direction = 'internal';
        } elseif ($viewerIsCaller) {
            $direction = 'outgoing';
        } elseif ($viewerIsCallee) {
            $direction = 'incoming';
        } elseif ($callerUserId !== null && $calleeUserId !== null) {
            $direction = 'internal';
        }

        return [
            'direction' => $direction,
            'party_status' => $this->partyStatusForDirection($direction),
        ];
    }

    private function partyStatusForDirection(string $direction): string
    {
        return match ($this->status?->value ?? $this->status) {
            'completed' => 'answered',
            'in_progress' => 'ongoing',
            'ringing' => 'ringing',
            'busy' => $direction === 'incoming' ? 'missed' : 'busy',
            'failed' => 'failed',
            'no_answer' => $direction === 'outgoing' ? 'unanswered' : 'missed',
            'canceled' => $direction === 'incoming' ? 'missed' : 'canceled',
            default => 'unknown',
        };
    }
}
