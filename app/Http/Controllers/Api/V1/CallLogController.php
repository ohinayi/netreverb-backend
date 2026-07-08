<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CallStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCallLogRequest;
use App\Http\Requests\Api\V1\UpdateCallLogRequest;
use App\Http\Resources\Api\V1\CallLogResource;
use App\Models\CallLog;
use App\Models\Extension;
use App\Models\Organization;
use App\Services\CallRecordings\CallRecordingManager;
use App\Services\Telephony\FreeSwitchCallUuidSynchronizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class CallLogController extends Controller
{
    public function __construct(
        private readonly FreeSwitchCallUuidSynchronizer $uuidSynchronizer,
        private readonly CallRecordingManager $recordingManager,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [CallLog::class, $organization]);

        // Only Owner/Admin can view all call logs. Regular members are limited to their own.
        $canManage = Gate::allows('viewAll', [CallLog::class, $organization]);

        $filter = $request->string('filter', 'all')->toString();

        if (! in_array($filter, ['all', 'incoming', 'outgoing', 'missed'], true)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'The selected filter is invalid.');
        }

        $callLogQuery = $organization->callLogs()
            ->with(['callerExtension.dialableNumber', 'calleeExtension.dialableNumber'])
            ->when(
                ! $canManage,
                function ($query) use ($request): void {
                    $userExtensionIds = $request->user()->extensions()->pluck('id')->toArray();
                    $query->where(function ($q) use ($userExtensionIds): void {
                        $q->whereIn('caller_extension_id', $userExtensionIds)
                            ->orWhereIn('callee_extension_id', $userExtensionIds);
                    });
                }
            )
            ->when($filter === 'incoming', function ($query): void {
                $query->whereNull('caller_extension_id')
                    ->whereNotNull('callee_extension_id');
            })
            ->when($filter === 'outgoing', function ($query): void {
                $query->whereNotNull('caller_extension_id')
                    ->whereNull('callee_extension_id');
            })
            ->when($filter === 'missed', function ($query): void {
                $query->whereNull('caller_extension_id')
                    ->whereNotNull('callee_extension_id')
                    ->whereIn('status', [
                        CallStatus::Busy,
                        CallStatus::NoAnswer,
                        CallStatus::Canceled,
                    ]);
            })
            ->latest();

        $callLogs = $callLogQuery->paginate(10);

        Log::info('Call log index retrieved.', [
            'organization_id' => $organization->public_id,
            'call_log_count' => $callLogs->count(),
            'filter' => $filter,
        ]);

        return CallLogResource::collection($callLogs);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCallLogRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('create', [CallLog::class, $organization]);

        $data = $request->validated();

        if ($request->filled('caller_extension_public_id')) {
            $data['caller_extension_id'] = Extension::query()
                ->where('public_id', $request->string('caller_extension_public_id'))
                ->value('id');
        }
        unset($data['caller_extension_public_id']);

        if ($request->filled('callee_extension_public_id')) {
            $data['callee_extension_id'] = Extension::query()
                ->where('public_id', $request->string('callee_extension_public_id'))
                ->value('id');
        }
        unset($data['callee_extension_public_id']);

        $callLog = $organization->callLogs()->create($data);
        $this->prepareRecordingInfrastructure($callLog);

        Log::info('Call log created.', [
            'call_log_id' => $callLog->public_id,
            'caller_number' => $callLog->caller_number,
            'callee_number' => $callLog->callee_number,
            'freeswitch_uuid' => $callLog->freeswitch_uuid,
            'status' => $callLog->status instanceof \BackedEnum ? $callLog->status->value : $callLog->status,
        ]);

        return CallLogResource::make($callLog->load(['callerExtension.dialableNumber', 'calleeExtension.dialableNumber']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Organization $organization, CallLog $callLog): CallLogResource
    {
        Gate::authorize('view', $callLog);

        if ($callLog->freeswitch_uuid === null
            && in_array($callLog->status, [CallStatus::Ringing, CallStatus::InProgress], true)) {
            $matchedCount = $this->uuidSynchronizer->syncOnce();

            Log::info('Call log show triggered FreeSWITCH UUID sync.', [
                'call_log_id' => $callLog->public_id,
                'matched_count' => $matchedCount,
            ]);

            $callLog->refresh();
        }

        return CallLogResource::make($callLog->load(['callerExtension.dialableNumber', 'calleeExtension.dialableNumber']));
    }

    /**
     * Transform the resource into an array.
     */
    public function update(
        UpdateCallLogRequest $request,
        Organization $organization,
        CallLog $callLog,
    ): CallLogResource {
        Gate::authorize('update', $callLog);

        $data = $request->validated();

        if (array_key_exists('freeswitch_uuid', $data) && $data['freeswitch_uuid'] === null) {
            unset($data['freeswitch_uuid']);
        }

        $callLog->update($data);
        $this->prepareRecordingInfrastructure($callLog->refresh());

        Log::info('Call log updated.', [
            'call_log_id' => $callLog->public_id,
            'caller_number' => $callLog->caller_number,
            'callee_number' => $callLog->callee_number,
            'freeswitch_uuid' => $callLog->freeswitch_uuid,
            'status' => $callLog->status instanceof \BackedEnum ? $callLog->status->value : $callLog->status,
        ]);

        return CallLogResource::make($callLog->load(['callerExtension.dialableNumber', 'calleeExtension.dialableNumber']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Organization $organization, CallLog $callLog): Response
    {
        Gate::authorize('delete', $callLog);

        $callLog->delete();

        return response()->noContent();
    }

    private function prepareRecordingInfrastructure(CallLog $callLog): void
    {
        $callUuid = (string) ($callLog->freeswitch_uuid ?? '');

        if ($callUuid === '') {
            return;
        }

        if (! in_array($callLog->status, [CallStatus::Ringing, CallStatus::InProgress], true)) {
            return;
        }

        $this->recordingManager->prepare($callLog, $callUuid);
    }
}
