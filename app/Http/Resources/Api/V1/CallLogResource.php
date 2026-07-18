<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\CallRecordingStatus;
use App\Enums\CallStatus;
use App\Models\CallRecordingUpload;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
        $callPerspective = $this->callPerspective($request);
        $hasVisibleRecording = $recordingStatus !== null;
        $recordingUpload = $this->whenLoaded('recordingUpload');
        $telephonyStatus = $request->attributes->get('telephony_status', [
            'status' => 'ok',
            'reason' => null,
        ]);

        return [
            'id' => $this->public_id,
            'caller_number' => $this->caller_number,
            'callee_number' => $this->callee_number,
            'status' => $this->status,
            'media_type' => $this->media_type,
            'session_type' => $this->session_type,
            'direction' => $callPerspective['direction'],
            'party_status' => $callPerspective['party_status'],
            'is_missed' => $callPerspective['party_status'] === 'missed',
            'is_answered' => $callPerspective['party_status'] === 'answered',
            'duration' => $this->duration,
            'freeswitch_uuid' => $attributes['freeswitch_uuid'] ?? null,
            'recording' => $hasVisibleRecording ? [
                'url' => $recordingUrl ?? $this->recordingUrlFor($request),
                'duration' => $attributes['recording_duration'] ?? null,
                'size' => $attributes['recording_size'] ?? null,
                'status' => $recordingStatus,
                'media_type' => $this->recording_media_type,
                'container' => $attributes['recording_container'] ?? null,
                'file_name' => $attributes['recording_file_name'] ?? null,
                'playback_available' => $playbackAvailable,
                'upload' => $recordingUpload instanceof CallRecordingUpload
                    ? [
                        'strategy' => 'client_chunks',
                        'status' => $recordingUpload->status,
                        'session_id' => $recordingUpload->public_id,
                        'next_sequence' => $recordingUpload->next_sequence,
                        'chunk_upload_url' => route('organizations.call-logs.recording.upload-chunk', [
                            'organization' => $this->organizationPublicId(),
                            'callLog' => $this->public_id,
                        ]),
                        'finalize_url' => route('organizations.call-logs.recording.finalize-upload', [
                            'organization' => $this->organizationPublicId(),
                            'callLog' => $this->public_id,
                        ]),
                    ]
                    : null,
            ] : null,
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'caller_extension' => ExtensionResource::make($this->whenLoaded('callerExtension')),
            'callee_extension' => ExtensionResource::make($this->whenLoaded('calleeExtension')),
            'telephony' => [
                'status' => $telephonyStatus['status'] ?? 'ok',
                'reason' => $telephonyStatus['reason'] ?? null,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function recordingUrlFor(Request $request): ?string
    {
        $organizationPublicId = $this->organizationPublicId($request);

        if ($organizationPublicId === null) {
            return null;
        }

        return route('organizations.call-logs.recording.show', [
            'organization' => $organizationPublicId,
            'callLog' => $this->public_id,
        ]);
    }

    private function organizationPublicId(?Request $request = null): ?string
    {
        $organization = $request?->route('organization');

        if ($organization instanceof Organization) {
            return $organization->public_id;
        }

        return $this->organization?->public_id
            ?? Organization::query()->whereKey($this->organization_id)->value('public_id');
    }

    private function recordingPlaybackAvailable(
        ?string $recordingStatus,
        ?string $recordingUrl,
        ?string $recordingFilePath,
    ): bool {
        if ($recordingStatus !== CallRecordingStatus::Completed->value) {
            return false;
        }

        if ($recordingFilePath !== null && $recordingFilePath !== '') {
            return Storage::disk(config('telephony.call_recordings.disk'))->exists($recordingFilePath);
        }

        return $recordingUrl !== null && $recordingUrl !== '';
    }

    /**
     * @return array{direction: string, party_status: string}
     */
    private function callPerspective(Request $request): array
    {
        if ($this->caller_extension_id !== null && $this->callee_extension_id !== null) {
            $viewerUserId = $request->user()?->getKey();
            $callerUserId = $this->callerExtension?->user_id;
            $calleeUserId = $this->calleeExtension?->user_id;

            if ($viewerUserId !== null && $viewerUserId === $callerUserId) {
                $direction = 'outgoing';
            } elseif ($viewerUserId !== null && $viewerUserId === $calleeUserId) {
                $direction = 'incoming';
            } else {
                $direction = 'internal';
            }
        } elseif ($this->caller_extension_id !== null) {
            $direction = 'outgoing';
        } elseif ($this->callee_extension_id !== null) {
            $direction = 'incoming';
        } else {
            $direction = 'external';
        }

        return [
            'direction' => $direction,
            'party_status' => $this->partyStatusForDirection($direction),
        ];
    }

    private function partyStatusForDirection(string $direction): string
    {
        return match ($this->status?->value ?? $this->status) {
            CallStatus::Completed->value => 'answered',
            CallStatus::InProgress->value => 'ongoing',
            CallStatus::Ringing->value => 'ringing',
            'busy' => $direction === 'incoming' ? 'missed' : 'busy',
            CallStatus::Failed->value => 'failed',
            CallStatus::NoAnswer->value => $direction === 'outgoing' ? 'unanswered' : 'missed',
            CallStatus::Canceled->value => $direction === 'incoming' ? 'missed' : 'canceled',
            default => 'unknown',
        };
    }
}
