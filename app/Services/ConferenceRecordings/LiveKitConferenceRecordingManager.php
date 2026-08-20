<?php

namespace App\Services\ConferenceRecordings;

use Agence104\LiveKit\EgressServiceClient;
use Agence104\LiveKit\RoomServiceClient;
use App\Enums\ConferenceRecordingStatus;
use App\Enums\ConferenceRecordingTrackStatus;
use App\Models\ConferenceRecording;
use App\Models\ConferenceRecordingTrack;
use App\Models\ConferenceRoom;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livekit\DirectFileOutput;
use Livekit\ParticipantInfo;
use Livekit\S3Upload;
use Livekit\TrackInfo;
use Livekit\TrackSource;
use Throwable;

class LiveKitConferenceRecordingManager
{
    /**
     * Track kinds this manager records, keyed by LiveKit TrackSource, with
     * the file extension raw (non-transcoded) Egress writes for that kind.
     * Room Composite Egress renders and re-encodes the full canvas in real
     * time — that was the 1-2 vCPU/recording cost. Track *Composite* Egress
     * (an earlier version of this file) turned out not to fix that: it still
     * forces a full re-encode per track via EncodingOptions, so N tracks can
     * cost as much or more than one composite encode. Plain Track Egress with
     * no encoding options writes the incoming bytes straight to disk — no
     * server-side decode/encode at all. CombineConferenceRecordingTracks pairs
     * each participant's raw audio+video back together (cheap stream-copy
     * mux) and only does real encoding work offline, after the call ends.
     */
    /**
     * The extension here is only the filepath we request — LiveKit actually
     * picks the real container from the source codec and reports the real
     * path back in the completion webhook (see
     * ConferenceRecordingController::webhook(), which corrects storage_key
     * from that report). Confirmed live: browser video tracks (VP8) come
     * back as .webm regardless of what's requested here.
     */
    private const TRACK_KINDS = [
        TrackSource::MICROPHONE => ['kind' => 'microphone', 'extension' => 'ogg'],
        TrackSource::CAMERA => ['kind' => 'camera', 'extension' => 'webm'],
        TrackSource::SCREEN_SHARE => ['kind' => 'screen_share', 'extension' => 'webm'],
        TrackSource::SCREEN_SHARE_AUDIO => ['kind' => 'screen_share_audio', 'extension' => 'ogg'],
    ];
    public function start(ConferenceRoom $room): ConferenceRecording
    {
        $recording = DB::transaction(function () use ($room): ConferenceRecording {
            $active = $room->recordings()
                ->whereIn('status', [ConferenceRecordingStatus::Starting, ConferenceRecordingStatus::Recording])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($active) {
                return $active;
            }

            $key = 'conferences/'.$room->public_id.'/'.now()->format('Y/m/d/').Str::ulid().'.mp4';

            return $room->recordings()->create([
                'recording_id' => (string) Str::ulid(),
                'room_id' => 'netreverb-conference-'.$room->public_id,
                'call_id' => (string) Str::ulid(),
                'egress_id' => null,
                'file_path' => $key,
                'file_name' => basename($key),
                'storage_key' => $key,
                'status' => ConferenceRecordingStatus::Starting,
            ]);
        });

        if ($recording->tracks()->exists()) {
            return $recording;
        }

        $roomClient = new RoomServiceClient(
            config('livekit.egress_api_url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );

        try {
            $participants = $roomClient->listParticipants($recording->room_id)->getParticipants();
        } catch (Throwable $exception) {
            Log::warning('LiveKit listParticipants failed while starting recording.', [
                'recording_id' => $recording->recording_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $participants = [];
        }

        $startedAny = false;
        foreach ($participants as $participant) {
            /** @var ParticipantInfo $participant */
            $startedAny = $this->startTracksForParticipant($recording, $participant) || $startedAny;
        }

        $recording->forceFill([
            'status' => ConferenceRecordingStatus::Recording,
        ])->save();

        if (! $startedAny) {
            Log::info('Recording started with no active tracks yet; sync command will pick up joiners.', [
                'recording_id' => $recording->recording_id,
                'room_id' => $recording->room_id,
            ]);
        }

        return $recording->fresh();
    }

    /**
     * Starts a raw Track Egress for each of a participant's recordable tracks
     * (mic, camera, screen share, screen share audio) that isn't already
     * covered. Safe to call again for a participant who already has some or
     * all tracks recording. Used both by start() and by the periodic sync
     * command that covers participants who join after recording has begun.
     */
    public function startTracksForParticipant(ConferenceRecording $recording, ParticipantInfo $participant): bool
    {
        $identity = $participant->getIdentity();
        $alreadyCovered = $recording->tracks()
            ->where('participant_identity', $identity)
            ->pluck('kind')
            ->all();

        $startedAny = false;

        foreach ($participant->getTracks() as $track) {
            /** @var TrackInfo $track */
            $trackKind = self::TRACK_KINDS[$track->getSource()] ?? null;
            if ($trackKind === null || in_array($trackKind['kind'], $alreadyCovered, true)) {
                continue;
            }

            $startedAny = $this->startRawTrackEgress(
                $recording,
                $identity,
                $trackKind['kind'],
                $trackKind['extension'],
                $track->getSid(),
            )|| $startedAny;
        }

        return $startedAny;
    }

    private function startRawTrackEgress(
        ConferenceRecording $recording,
        string $identity,
        string $kind,
        string $extension,
        string $trackId,
    ): bool {
        $slug = Str::slug($identity) ?: 'participant';
        $storageKey = sprintf(
            'conferences/%s/tracks/%s-%s-%s.%s',
            $recording->recording_id,
            $slug,
            $kind,
            Str::lower(Str::random(6)),
            $extension,
        );

        $output = new DirectFileOutput([
            'filepath' => $storageKey,
            's3' => new S3Upload([
                'access_key' => (string) config('livekit.recording_s3_access_key'),
                'secret' => (string) config('livekit.recording_s3_secret'),
                'endpoint' => (string) config('livekit.recording_s3_endpoint'),
                'region' => (string) config('livekit.recording_s3_region', 'us-east-1'),
                'bucket' => (string) config('livekit.recording_bucket'),
                'force_path_style' => true,
            ]),
        ]);

        $client = new EgressServiceClient(
            config('livekit.egress_api_url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );

        try {
            $info = $client->startTrackEgress($recording->room_id, $output, $trackId);

            ConferenceRecordingTrack::query()->create([
                'conference_recording_id' => $recording->id,
                'egress_id' => $info->getEgressId(),
                'participant_identity' => $identity,
                'kind' => $kind,
                'storage_key' => $storageKey,
                'status' => ConferenceRecordingTrackStatus::Recording,
            ]);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Failed to start raw track egress.', [
                'recording_id' => $recording->recording_id,
                'participant_identity' => $identity,
                'kind' => $kind,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Room end/expiry/last-participant-left previously only stopped the
     * FreeSWITCH-based recorder — a LiveKit recording left running past room
     * end never got a stop() call, so its Track/Room Composite Egress workers
     * kept burning CPU indefinitely and the recording row stayed stuck in
     * "recording". Call this from the same lifecycle points that stop the
     * FreeSWITCH recorder.
     */
    public function stopActiveForRoom(ConferenceRoom $room): void
    {
        $recording = $room->recordings()
            ->whereIn('status', [
                ConferenceRecordingStatus::Starting,
                ConferenceRecordingStatus::Recording,
                ConferenceRecordingStatus::Stopping,
            ])
            ->latest('id')
            ->first();

        if ($recording !== null) {
            $this->stop($recording);
        }
    }

    public function stop(ConferenceRecording $recording): ConferenceRecording
    {
        // Flip to Stopping FIRST, before touching any track. The sync-tracks
        // cron (SyncConferenceRecordingTracks, every 10s) only starts new
        // track egresses for recordings still in status===Recording - doing
        // this first closes most of the window where a track upgrade (e.g.
        // camera published mid-call) could have its egress started by that
        // cron concurrently with us tearing everything else down here, which
        // previously left that one egress running forever with no DB row
        // stop() ever knew to grab.
        $recording->forceFill(['status' => ConferenceRecordingStatus::Stopping])->save();

        $client = new EgressServiceClient(
            config('livekit.egress_api_url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );

        // Query and stop twice: a track row can still be mid-INSERT (its
        // egress already started at LiveKit, but the DB write from
        // startRawTrackEgress() hasn't landed yet) when the first query runs.
        // The short sleep between passes gives that in-flight insert time to
        // commit so the second pass actually catches it.
        $stoppedEgressIds = [];
        for ($pass = 0; $pass < 2; $pass++) {
            $activeTracks = $recording->tracks()
                ->whereIn('status', [ConferenceRecordingTrackStatus::Starting, ConferenceRecordingTrackStatus::Recording])
                ->get();

            foreach ($activeTracks as $track) {
                if (in_array($track->egress_id, $stoppedEgressIds, true)) {
                    continue;
                }

                try {
                    $client->stopEgress($track->egress_id);
                    $track->forceFill(['status' => ConferenceRecordingTrackStatus::Stopping])->save();
                    $stoppedEgressIds[] = $track->egress_id;
                } catch (Throwable $exception) {
                    Log::warning('Failed to stop track composite egress.', [
                        'recording_id' => $recording->recording_id,
                        'track_id' => $track->id,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            if ($pass === 0) {
                usleep(500_000);
            }
        }

        $recording->forceFill([
            'duration' => max(0, now()->diffInSeconds($recording->created_at)),
        ])->save();

        return $recording->refresh();
    }
}
