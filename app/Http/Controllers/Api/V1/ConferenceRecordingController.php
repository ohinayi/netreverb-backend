<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConferenceRecordingStatus;
use App\Enums\ConferenceRecordingTrackStatus;
use App\Http\Controllers\Controller;
use App\Jobs\CombineConferenceRecordingTracks;
use App\Models\ConferenceRecording;
use App\Models\ConferenceRecordingTrack;
use App\Models\ConferenceRoom;
use App\Models\Organization;
use App\Services\ConferenceRecordings\ConferenceRecordingManager;
use App\Services\ConferenceRecordings\LiveKitConferenceRecordingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Agence104\LiveKit\WebhookReceiver;
use Livekit\EgressStatus;
use Illuminate\Http\Request;

class ConferenceRecordingController extends Controller
{
    public function __construct(
        private readonly ConferenceRecordingManager $recordingManager,
        private readonly LiveKitConferenceRecordingManager $liveKitRecordingManager,
    ) {}

    public function index(Organization $organization): JsonResponse
    {
        Gate::authorize('viewAny', [ConferenceRecording::class, $organization]);

        $recordings = ConferenceRecording::query()
            ->whereHas('conferenceRoom', fn ($query) => $query->where('organization_id', $organization->id))
            ->with(['conferenceRoom:id,public_id,title,host_user_id', 'conferenceRoom.hostUser:id,public_id,name'])
            ->latest('id')
            ->paginate(25)
            ->through(fn (ConferenceRecording $recording) => [
                'recording_id' => $recording->recording_id,
                'room_id' => $recording->conferenceRoom?->public_id,
                'room_title' => $recording->conferenceRoom?->title,
                'host_name' => $recording->conferenceRoom?->hostUser?->name,
                'status' => $recording->status->value,
                'duration' => $recording->duration,
                'size' => $recording->size,
                'created_at' => $recording->created_at?->toIso8601String(),
                'download_url' => $recording->status === ConferenceRecordingStatus::Completed && $recording->storage_key
                    ? Storage::disk('livekit_recordings')->temporaryUrl(
                        $recording->storage_key,
                        now()->addHour(),
                        ['ResponseContentDisposition' => 'attachment; filename="'.($recording->conferenceRoom?->title ?: 'recording').'-'.$recording->recording_id.'.mp4"'],
                    )
                    : null,
            ]);

        return response()->json($recordings);
    }

    public function start(Organization $organization, ConferenceRoom $conferenceRoom): JsonResponse
    {
        Gate::authorize('create', [$organization]);

        $recording = $this->liveKitRecordingManager->start($conferenceRoom);

        return response()->json(['data' => $recording->fresh()]);
    }

    public function stop(Organization $organization, ConferenceRoom $conferenceRoom): JsonResponse
    {
        Gate::authorize('create', [$organization]);

        $recording = $conferenceRoom->recordings()
            ->whereIn('status', ['starting', 'recording', 'stopping'])
            ->latest('id')
            ->first();

        if ($recording === null) {
            return response()->json(['data' => null, 'message' => 'No active recording.']);
        }

        return response()->json(['data' => $this->liveKitRecordingManager->stop($recording)]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $authorization = preg_replace('/^Bearer\s+/i', '', (string) $request->header('Authorization'));
        $event = (new WebhookReceiver(config('livekit.api_key'), config('livekit.api_secret')))
            ->receive($request->getContent(), $authorization);
        $info = $event->getEgressInfo();

        if ($info === null || $info->getEgressId() === '') {
            return response()->json(['message' => 'Ignored webhook.']);
        }

        $status = match ($info->getStatus()) {
            EgressStatus::EGRESS_COMPLETE => ConferenceRecordingTrackStatus::Completed,
            EgressStatus::EGRESS_FAILED,
            EgressStatus::EGRESS_ABORTED,
            EgressStatus::EGRESS_LIMIT_REACHED => ConferenceRecordingTrackStatus::Failed,
            EgressStatus::EGRESS_ENDING => ConferenceRecordingTrackStatus::Stopping,
            default => ConferenceRecordingTrackStatus::Recording,
        };

        $track = ConferenceRecordingTrack::query()->where('egress_id', $info->getEgressId())->first();
        if ($track === null) {
            return response()->json(['message' => 'Ignored webhook.']);
        }

        $fileResult = $info->getFileResults()[0] ?? null;

        $track->forceFill([
            'status' => $status,
            'duration' => $fileResult !== null && $fileResult->getDuration() > 0
                ? max(0, (int) ($fileResult->getDuration() / 1_000_000_000))
                : $track->duration,
            'size' => $fileResult?->getSize() ?: $track->size,
            // Raw Track Egress picks the actual container/extension from the
            // source codec (e.g. VP8 -> .webm), ignoring whatever extension we
            // requested in the filepath. Trust LiveKit's own report of where
            // it actually wrote the file instead of the path we guessed.
            'storage_key' => $fileResult?->getFilename() ?: $track->storage_key,
        ])->save();

        $this->maybeFinalizeRecording($track->conferenceRecording);

        return response()->json(['message' => 'Webhook accepted.']);
    }

    /**
     * A recording only stops requesting new tracks once its own status moves
     * past Stopping (see LiveKitConferenceRecordingManager::stop()). Once
     * every track this recording ever started has reached a terminal state,
     * hand off to the combine job — or mark the whole recording Failed if
     * nothing usable came out of it.
     */
    private function maybeFinalizeRecording(?ConferenceRecording $recording): void
    {
        if ($recording === null || $recording->status !== ConferenceRecordingStatus::Stopping) {
            return;
        }

        $tracks = $recording->tracks()->get();
        if ($tracks->isEmpty() || $tracks->contains(fn (ConferenceRecordingTrack $track) => ! $track->status->isTerminal())) {
            return;
        }

        $hasCompletedTrack = $tracks->contains(fn (ConferenceRecordingTrack $track) => $track->status === ConferenceRecordingTrackStatus::Completed);

        if (! $hasCompletedTrack) {
            $recording->forceFill(['status' => ConferenceRecordingStatus::Failed])->save();

            return;
        }

        $recording->forceFill(['status' => ConferenceRecordingStatus::Combining])->save();
        CombineConferenceRecordingTracks::dispatch($recording->id);
    }

    public function destroy(
        Organization $organization,
        ConferenceRoom $conferenceRoom,
        ConferenceRecording $conferenceRecording,
    ): Response {
        Gate::authorize('delete', $conferenceRecording);

        if ($conferenceRecording->conference_room_id !== $conferenceRoom->id) {
            abort(404);
        }

        // recordingManager->delete() only knows about the legacy FreeSWITCH
        // local-disk path (file_path). LiveKit recordings land in the S3
        // livekit_recordings disk under storage_key instead, so that has to
        // be removed separately — along with any raw per-track files that
        // never got cleaned up (e.g. a recording that failed before combine).
        $disk = Storage::disk('livekit_recordings');

        if ($conferenceRecording->storage_key) {
            $disk->delete($conferenceRecording->storage_key);
        }

        foreach ($conferenceRecording->tracks as $track) {
            if ($track->storage_key) {
                $disk->delete(array_filter([$track->storage_key, $track->manifestStorageKey()]));
            }
        }

        $this->recordingManager->delete($conferenceRecording);

        return response()->noContent();
    }
}
