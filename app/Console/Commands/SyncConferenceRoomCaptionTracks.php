<?php

namespace App\Console\Commands;

use Agence104\LiveKit\RoomServiceClient;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Services\ConferenceRecordings\LiveKitConferenceCaptionsManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Mirrors SyncConferenceRecordingTracks: there is no LiveKit
 * participant/track-published webhook to react to mid-call joiners, so
 * rooms with captions on are re-polled periodically and any newly-published
 * audio track gets its own caption egress. Idempotent - skips kinds already
 * covered per participant (see LiveKitConferenceCaptionsManager).
 */
class SyncConferenceRoomCaptionTracks extends Command
{
    protected $signature = 'conference-rooms:sync-caption-tracks';

    protected $description = 'Start caption track egress for participants who joined after captions were turned on.';

    public function handle(LiveKitConferenceCaptionsManager $captionsManager): int
    {
        $roomClient = new RoomServiceClient(
            config('livekit.egress_api_url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );

        $startedCount = 0;

        ConferenceRoom::query()
            ->where('status', ConferenceRoomStatus::Active)
            ->where('configuration->captions_enabled', true)
            ->chunkById(50, function ($rooms) use ($roomClient, $captionsManager, &$startedCount): void {
                foreach ($rooms as $room) {
                    try {
                        $participants = $roomClient->listParticipants('netreverb-conference-'.$room->public_id)->getParticipants();
                    } catch (Throwable $exception) {
                        report($exception);

                        continue;
                    }

                    foreach ($participants as $participant) {
                        if ($captionsManager->startTracksForParticipant($room, $participant)) {
                            $startedCount++;
                        }
                    }
                }
            });

        $this->info(sprintf('Started %d new caption track(s).', $startedCount));

        return self::SUCCESS;
    }
}
