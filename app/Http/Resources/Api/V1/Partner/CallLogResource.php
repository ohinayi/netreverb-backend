<?php

namespace App\Http\Resources\Api\V1\Partner;

use App\Enums\CallRecordingStatus;
use App\Enums\CallStatus;
use App\Http\Resources\Api\V1\CallLogParticipantResource;
use App\Http\Resources\Api\V1\ExtensionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Partner-facing shape of a call log - same field set as
 * Api\V1\CallLogResource, minus anything a partner API key can't act on:
 * no upload URLs (partners are read-only, they never upload a recording)
 * and no playback URL (that route requires session/Sanctum auth a partner
 * key can't satisfy). Recording *metadata* only. A signed, partner-scoped
 * download URL is a natural v2 addition, not built here.
 */
class CallLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();
        $recordingFilePath = $attributes['recording_file_path'] ?? null;
        $recordingUrl = $attributes['recording_url'] ?? null;
        $recordingStatus = isset($attributes['recording_status'])
            ? $this->recording_status?->value ?? $attributes['recording_status']
            : null;
        $playbackAvailable = $this->recordingPlaybackAvailable($recordingStatus, $recordingUrl, $recordingFilePath);
        $callPerspective = $this->callPerspective($request);
        $hasVisibleRecording = $recordingStatus !== null;

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
            'conference_name' => $attributes['conference_name'] ?? null,
            'participants' => $this->whenLoaded('participants', fn () => CallLogParticipantResource::collection($this->participants)),
            'recording' => $hasVisibleRecording ? [
                'duration' => $attributes['recording_duration'] ?? null,
                'size' => $attributes['recording_size'] ?? null,
                'status' => $recordingStatus,
                'media_type' => $this->recording_media_type,
                'container' => $attributes['recording_container'] ?? null,
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
     * $request->user() is always null on a partner request - matches the
     * first-party CallLogResource's own null-viewer fallback (both
     * extensions set + no viewer -> "internal"), so this is a verbatim
     * copy, not a rewrite.
     *
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
