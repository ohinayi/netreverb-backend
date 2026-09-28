<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Partner\CallLogResource;
use App\Models\CallLog;
use App\Models\Organization;
use App\Services\CallRecordings\CallRecordingManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public partner API - read-only calls/CDRs, authenticated by
 * AuthenticatePartnerApiKey (see routes/api.php's `v1/partner` group) not
 * Sanctum. An API key is an org-level credential with no "role" the way a
 * human user has one, so it sees every call log in its organization - the
 * same scope a human Owner/Admin gets in the first-party
 * Api\V1\CallLogController, via CallLogVisibility. No role-based
 * sub-filtering applies here.
 */
class CallLogController extends Controller
{
    public function __construct(private readonly CallRecordingManager $recordingManager) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('partner_organization');

        $callLogs = $organization->callLogs()
            ->with($this->callLogRelations())
            ->latest()
            ->paginate(min(100, (int) $request->integer('per_page', 25)));

        return CallLogResource::collection($callLogs);
    }

    public function show(Request $request, CallLog $callLog): CallLogResource
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('partner_organization');

        abort_unless($callLog->organization_id === $organization->id, 404);

        // Deliberately no live FreeSWITCH ESL re-sync here (unlike the
        // first-party show()) - that triggers real ESL traffic and this
        // endpoint is reachable by an external partner, not just our own
        // authenticated users. reconcileCompletedRecordingMetadata() is
        // local-storage-only, safe to keep.
        $callLog = $this->recordingManager->reconcileCompletedRecordingMetadata($callLog);

        return CallLogResource::make($callLog->load($this->callLogRelations()));
    }

    private function callLogRelations(): array
    {
        return [
            'callerExtension.dialableNumber',
            'callerExtension.user',
            'callerExtension.fallbackExtension',
            'calleeExtension.dialableNumber',
            'calleeExtension.user',
            'calleeExtension.fallbackExtension',
            'participants.extension.dialableNumber',
        ];
    }
}
