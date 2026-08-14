<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConferenceRoom;
use App\Models\Organization;
use App\Services\ConferenceRecordings\LiveKitConferenceCaptionsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ConferenceCaptionsController extends Controller
{
    public function __construct(
        private readonly LiveKitConferenceCaptionsManager $captionsManager,
    ) {}

    public function start(Organization $organization, ConferenceRoom $conferenceRoom): JsonResponse
    {
        // Same ability that already gates recording start/stop
        // (ConferenceRecordingController) - captions is the same kind of
        // call-wide, host-level toggle.
        Gate::authorize('create', [$organization]);

        $conferenceRoom->forceFill([
            'configuration' => array_merge($conferenceRoom->configuration ?? [], ['captions_enabled' => true]),
        ])->save();

        $this->captionsManager->start($conferenceRoom);

        return response()->json(['data' => ['captions_enabled' => true]]);
    }

    public function stop(Organization $organization, ConferenceRoom $conferenceRoom): JsonResponse
    {
        Gate::authorize('create', [$organization]);

        $this->captionsManager->stopActiveForRoom($conferenceRoom);

        $conferenceRoom->forceFill([
            'configuration' => array_merge($conferenceRoom->configuration ?? [], ['captions_enabled' => false]),
        ])->save();

        return response()->json(['data' => ['captions_enabled' => false]]);
    }
}
