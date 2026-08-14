<?php

namespace App\Services\ConferenceRecordings;

use Agence104\LiveKit\EgressServiceClient;
use Agence104\LiveKit\RoomServiceClient;
use App\Enums\ConferenceCaptionTrackStatus;
use App\Models\ConferenceCaptionTrack;
use App\Models\ConferenceRoom;
use App\Support\CaptionsRelayToken;
use Illuminate\Support\Facades\Log;
use Livekit\ParticipantInfo;
use Livekit\TrackInfo;
use Livekit\TrackSource;
use Throwable;

/**
 * Starts a second, parallel websocket-output Track Egress per audio track,
 * alongside (and independent of) LiveKitConferenceRecordingManager's
 * file-output egress used for call recording. No bot/agent participant is
 * needed - EgressServiceClient::startTrackEgress() accepts a plain URL
 * string in place of a DirectFileOutput, which LiveKit interprets as a
 * websocket destination for that track's raw audio. That destination is the
 * standalone Node captions relay, which forwards the audio to Deepgram.
 *
 * Mirrors LiveKitConferenceRecordingManager's start/startTracksForParticipant/
 * stop structure so the two stay easy to compare, but tracks against
 * ConferenceRoom directly (not a ConferenceRecording row) since captions run
 * independently of whether recording is on, and are ephemeral - no text is
 * ever persisted here, only egress bookkeeping.
 */
class LiveKitConferenceCaptionsManager
{
    private const TRACK_KINDS = [
        TrackSource::MICROPHONE => 'microphone',
        TrackSource::SCREEN_SHARE_AUDIO => 'screen_share_audio',
    ];

    private const ACTIVE_STATUSES = [
        ConferenceCaptionTrackStatus::Starting,
        ConferenceCaptionTrackStatus::Active,
    ];

    public function start(ConferenceRoom $room): void
    {
        $roomClient = new RoomServiceClient(
            config('livekit.egress_api_url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );

        try {
            $participants = $roomClient->listParticipants($this->roomId($room))->getParticipants();
        } catch (Throwable $exception) {
            Log::warning('LiveKit listParticipants failed while starting captions.', [
                'conference_room_id' => $room->public_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $participants = [];
        }

        foreach ($participants as $participant) {
            /** @var ParticipantInfo $participant */
            $this->startTracksForParticipant($room, $participant);
        }
    }

    /**
     * Called both by start() and by the periodic sync command that covers
     * participants who join after captions have already been turned on -
     * there is no LiveKit participant/track-published webhook to react to
     * mid-call joiners directly (same gap the recording pipeline already
     * lives with; see SyncConferenceRecordingTracks).
     */
    public function startTracksForParticipant(ConferenceRoom $room, ParticipantInfo $participant): bool
    {
        $identity = $participant->getIdentity();
        $alreadyCovered = ConferenceCaptionTrack::query()
            ->where('conference_room_id', $room->id)
            ->where('participant_identity', $identity)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->pluck('kind')
            ->all();

        $startedAny = false;

        foreach ($participant->getTracks() as $track) {
            /** @var TrackInfo $track */
            $kind = self::TRACK_KINDS[$track->getSource()] ?? null;
            if ($kind === null || in_array($kind, $alreadyCovered, true)) {
                continue;
            }

            $startedAny = $this->startRawTrackEgress($room, $identity, $kind, $track->getSid()) || $startedAny;
        }

        return $startedAny;
    }

    private function startRawTrackEgress(ConferenceRoom $room, string $identity, string $kind, string $trackId): bool
    {
        $client = new EgressServiceClient(
            config('livekit.egress_api_url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );

        try {
            $info = $client->startTrackEgress($this->roomId($room), $this->ingestUrl($room, $identity, $trackId), $trackId);

            ConferenceCaptionTrack::query()->create([
                'conference_room_id' => $room->id,
                'egress_id' => $info->getEgressId(),
                'participant_identity' => $identity,
                'kind' => $kind,
                'status' => ConferenceCaptionTrackStatus::Starting,
            ]);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Failed to start caption track egress.', [
                'conference_room_id' => $room->public_id,
                'participant_identity' => $identity,
                'kind' => $kind,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Stops every active caption egress for a room. Called on host
     * toggle-off and from the same room-lifecycle points that already stop
     * the recording egress (room end/expiry, last participant leaving) -
     * see EndConferenceRoom, TouchConferenceRoomExpiry,
     * UpdateConferenceRoomParticipantPresence.
     */
    public function stopActiveForRoom(ConferenceRoom $room): void
    {
        $tracks = ConferenceCaptionTrack::query()
            ->where('conference_room_id', $room->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->get();

        if ($tracks->isEmpty()) {
            return;
        }

        $client = new EgressServiceClient(
            config('livekit.egress_api_url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );

        foreach ($tracks as $track) {
            try {
                $client->stopEgress($track->egress_id);
            } catch (Throwable $exception) {
                Log::warning('Failed to stop caption track egress.', [
                    'conference_room_id' => $room->public_id,
                    'track_id' => $track->id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }

            $track->forceFill(['status' => ConferenceCaptionTrackStatus::Stopped])->save();
        }
    }

    private function roomId(ConferenceRoom $room): string
    {
        return 'netreverb-conference-'.$room->public_id;
    }

    /**
     * The signed URL LiveKit's egress worker connects to. The relay verifies
     * the same HMAC token scheme used for the frontend's captions-token
     * (App\Support\CaptionsRelayToken) - no separate auth mechanism needed
     * for the ingest side. The participant identity has to travel with the
     * URL (not just the room) since the relay needs it to tag each caption
     * line with the right speaker - Deepgram never sees or resolves
     * identity, the track is already isolated per person.
     */
    private function ingestUrl(ConferenceRoom $room, string $identity, string $trackId): string
    {
        // Egress can run for the whole call; give the ingest token enough
        // headroom to outlive a long meeting rather than the short TTL used
        // for frontend captions-token requests.
        $token = CaptionsRelayToken::issue([
            'conference_room_public_id' => $room->public_id,
            'identity' => $identity,
        ], 60 * 60 * 6);

        return sprintf(
            '%s/egress/%s/%s/%s?sig=%s',
            rtrim((string) config('captions.relay_url'), '/'),
            $this->roomId($room),
            rawurlencode($identity),
            $trackId,
            $token,
        );
    }
}
