<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConferenceRecording;
use App\Models\ConferenceRoom;
use App\Models\Organization;
use App\Services\ConferenceRecordings\ConferenceRecordingManager;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ConferenceRecordingController extends Controller
{
    public function __construct(
        private readonly ConferenceRecordingManager $recordingManager,
    ) {}

    public function destroy(
        Organization $organization,
        ConferenceRoom $conferenceRoom,
        ConferenceRecording $conferenceRecording,
    ): Response {
        Gate::authorize('delete', $conferenceRecording);

        $this->recordingManager->delete($conferenceRecording);

        return response()->noContent();
    }
}
