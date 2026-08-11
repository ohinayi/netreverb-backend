<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\RecordingResource;
use App\Models\CallLog;
use App\Models\Organization;
use App\Services\Authorization\CallLogVisibility;
use App\Services\CallRecordings\CallRecordingManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class RecordingController extends Controller
{
    public function __construct(
        private readonly CallLogVisibility $callLogVisibility,
        private readonly CallRecordingManager $recordingManager,
    ) {}

    /**
     * Independent recordings feed. Queries recording data directly off
     * call_logs rather than going through CallLogController's dedup/priority
     * collapsing, so every recording stays visible here even when its
     * owning call log row gets superseded in the Calls tab.
     */
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [CallLog::class, $organization]);

        $user = $request->user();
        $canViewAll = Gate::allows('viewAll', [CallLog::class, $organization]);
        $accessibleExtensionIds = $canViewAll
            ? []
            : $this->callLogVisibility->accessibleExtensionIds($user, $organization);

        $recordings = $organization->callLogs()
            ->with(['callerExtension.dialableNumber', 'callerExtension.user', 'calleeExtension.dialableNumber', 'calleeExtension.user'])
            ->whereNotNull('recording_status')
            ->when(! $canViewAll, function ($query) use ($accessibleExtensionIds): void {
                $query->where(function ($q) use ($accessibleExtensionIds): void {
                    $q->whereIn('caller_extension_id', $accessibleExtensionIds)
                        ->orWhereIn('callee_extension_id', $accessibleExtensionIds);
                });
            })
            ->orderByRaw('COALESCE(recording_started_at, created_at) DESC')
            ->paginate(20);

        $recordings->getCollection()->transform(
            fn (CallLog $callLog): CallLog => $this->recordingManager->reconcileCompletedRecordingMetadata($callLog)
        );

        return RecordingResource::collection($recordings);
    }
}
