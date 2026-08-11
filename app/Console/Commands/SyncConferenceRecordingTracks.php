<?php

namespace App\Console\Commands;

use Agence104\LiveKit\RoomServiceClient;
use App\Enums\ConferenceRecordingStatus;
use App\Models\ConferenceRecording;
use App\Services\ConferenceRecordings\LiveKitConferenceRecordingManager;
use Illuminate\Console\Command;
use Throwable;

class SyncConferenceRecordingTracks extends Command
{
    protected $signature = 'conference-recordings:sync-tracks';

    protected $description = 'Start Track Composite Egress for participants who joined after a recording began.';

    public function handle(LiveKitConferenceRecordingManager $recordingManager): int
    {
        $roomClient = new RoomServiceClient(
            config('livekit.egress_api_url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );

        $startedCount = 0;

        ConferenceRecording::query()
            ->where('status', ConferenceRecordingStatus::Recording)
            ->chunkById(50, function ($recordings) use ($roomClient, $recordingManager, &$startedCount): void {
                foreach ($recordings as $recording) {
                    try {
                        $participants = $roomClient->listParticipants($recording->room_id)->getParticipants();
                    } catch (Throwable $exception) {
                        report($exception);

                        continue;
                    }

                    foreach ($participants as $participant) {
                        if ($recordingManager->startTracksForParticipant($recording, $participant)) {
                            $startedCount++;
                        }
                    }
                }
            });

        $this->info(sprintf('Started %d new recording track(s).', $startedCount));

        return self::SUCCESS;
    }
}
