<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Telephony\FreeSwitchCallGateway;
use App\Enums\CallRecordingStatus;
use App\Enums\CallStatus;
use App\Enums\ExtensionStatus;
use App\Exceptions\FreeSwitchTransferException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCallLogRequest;
use App\Http\Requests\Api\V1\TransferCallRequest;
use App\Http\Requests\Api\V1\UpdateCallLogRequest;
use App\Http\Resources\Api\V1\CallLogResource;
use App\Models\CallLog;
use App\Models\Extension;
use App\Models\Organization;
use App\Services\Auditing\AuditLogger;
use App\Services\Authorization\CallLogVisibility;
use App\Services\CallRecordings\CallRecordingManager;
use App\Services\Telephony\FreeSwitchCallUuidSynchronizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class CallLogController extends Controller
{
    public function __construct(
        private readonly FreeSwitchCallUuidSynchronizer $uuidSynchronizer,
        private readonly CallRecordingManager $recordingManager,
        private readonly FreeSwitchCallGateway $callGateway,
        private readonly CallLogVisibility $callLogVisibility,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [CallLog::class, $organization]);

        // Owners and admins can view all logs. Supervisors are limited to the
        // extensions assigned to their department; agents see their own.
        $canViewAll = Gate::allows('viewAll', [CallLog::class, $organization]);
        $accessibleExtensionIds = $canViewAll
            ? []
            : $this->callLogVisibility->accessibleExtensionIds($request->user(), $organization);

        $filter = $request->string('filter', 'all')->toString();

        if (! in_array($filter, ['all', 'incoming', 'outgoing', 'missed'], true)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'The selected filter is invalid.');
        }

        $callLogQuery = $organization->callLogs()
            ->with([
                'callerExtension.dialableNumber',
                'callerExtension.user',
                'callerExtension.fallbackExtension',
                'calleeExtension.dialableNumber',
                'calleeExtension.user',
                'calleeExtension.fallbackExtension',
            ])
            ->withCount('notes')
            ->when(
                ! $canViewAll,
                function ($query) use ($accessibleExtensionIds): void {
                    $query->where(function ($q) use ($accessibleExtensionIds): void {
                        $q->whereIn('caller_extension_id', $accessibleExtensionIds)
                            ->orWhereIn('callee_extension_id', $accessibleExtensionIds);
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

        $callLogs->getCollection()->transform(function (CallLog $callLog): CallLog {
            return $this->recordingManager->reconcileCompletedRecordingMetadata($callLog);
        });
        $callLogs->setCollection($this->deduplicateCallLogs($callLogs->getCollection()));

        Log::info('Call log index retrieved.', [
            'organization_id' => $organization->public_id,
            'call_log_count' => $callLogs->count(),
            'filter' => $filter,
        ]);

        return CallLogResource::collection($callLogs);
    }

    public function transfer(
        TransferCallRequest $request,
        Organization $organization,
        CallLog $callLog,
    ): CallLogResource {
        Gate::authorize('transfer', $callLog);
        abort_unless($callLog->organization_id === $organization->id, Response::HTTP_NOT_FOUND);

        $callUuid = $callLog->freeswitch_uuid;
        abort_if($callUuid === null || $callUuid === '', Response::HTTP_CONFLICT,
            'This call is not connected to FreeSWITCH yet. Try again once it is active.');

        $destination = $request->string('destination')->toString();
        $destinationExtension = Extension::query()
            ->where('organization_id', $organization->id)
            ->whereHas('dialableNumber', fn ($query) => $query->where('number', $destination))
            ->first();

        if ($destinationExtension === null || $destinationExtension->status !== ExtensionStatus::Active) {
            throw ValidationException::withMessages([
                'destination' => 'Safe transfer is currently available only to an active extension in this organization.',
            ]);
        }

        try {
            $this->callGateway->transfer(
                $callUuid,
                $destination,
                $callLog->caller_number,
                $destinationExtension->ring_timeout_seconds ?? 20,
            );
        } catch (FreeSwitchTransferException $exception) {
            throw ValidationException::withMessages(['destination' => $exception->getMessage()]);
        }

        Log::info('Active call transferred.', [
            'call_log_id' => $callLog->public_id,
            'organization_id' => $organization->public_id,
            'destination' => $request->string('destination')->toString(),
        ]);
        $this->auditLogger->record(
            $request,
            $request->user(),
            $organization,
            'call.transferred',
            $callLog,
            after: ['destination' => $destination],
        );

        return CallLogResource::make($callLog->load($this->callLogRelations()));
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

        $data = $this->stripDuplicateFreeSwitchUuid($data);

        // The client never sends this - every call log has been created with
        // a null started_at, which starved anything bucketing calls by date
        // (super-admin activity chart, per-organization usage) of any data
        // at all despite calls completing normally with real durations.
        $data['started_at'] ??= now();

        $callLog = $organization->callLogs()->create($data);
        $this->prepareRecordingInfrastructure($callLog);

        Log::info('Call log created.', [
            'call_log_id' => $callLog->public_id,
            'caller_number' => $callLog->caller_number,
            'callee_number' => $callLog->callee_number,
            'freeswitch_uuid' => $callLog->freeswitch_uuid,
            'status' => $callLog->status instanceof \BackedEnum ? $callLog->status->value : $callLog->status,
        ]);

        return CallLogResource::make($callLog->load($this->callLogRelations()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Organization $organization, CallLog $callLog): CallLogResource
    {
        Gate::authorize('view', $callLog);

        $telephonyStatus = [
            'status' => 'ok',
            'reason' => null,
        ];

        if ($callLog->freeswitch_uuid === null
            && in_array($callLog->status, [CallStatus::Ringing, CallStatus::InProgress], true)) {
            try {
                $matchedCount = $this->uuidSynchronizer->syncOnce();

                Log::info('Call log show triggered FreeSWITCH UUID sync.', [
                    'call_log_id' => $callLog->public_id,
                    'matched_count' => $matchedCount,
                ]);

                $callLog->refresh();
            } catch (Throwable $exception) {
                $telephonyStatus = [
                    'status' => 'degraded',
                    'reason' => 'event_socket_unavailable',
                ];

                Log::warning('Call log show could not refresh live FreeSWITCH UUID data. Returning persisted call-log data instead.', [
                    'call_log_id' => $callLog->public_id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $callLog = $this->recordingManager->reconcileCompletedRecordingMetadata($callLog);
        $request->attributes->set('telephony_status', $telephonyStatus);

        return CallLogResource::make($callLog->load($this->callLogRelations()));
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
        $recordingWasActive = in_array($callLog->recording_status, [CallRecordingStatus::Starting, CallRecordingStatus::Recording], true);

        // reset_freeswitch_uuid tells us a call log's channel changed out
        // from under it (e.g. it was resolved to a now-dead channel).
        // FreeSwitchCallUuidSynchronizer only ever matches a call log whose
        // freeswitch_uuid is still NULL - clearing it here lets that same
        // sync job re-resolve the real, current channel.
        $resetFreeSwitchUuid = (bool) ($data['reset_freeswitch_uuid'] ?? false);
        unset($data['reset_freeswitch_uuid']);

        if (array_key_exists('freeswitch_uuid', $data) && $data['freeswitch_uuid'] === null) {
            unset($data['freeswitch_uuid']);
        }

        if ($resetFreeSwitchUuid) {
            $data['freeswitch_uuid'] = null;
        }

        $data = $this->stripDuplicateFreeSwitchUuid($data, $callLog);

        $callLog->update($data);
        $callLog->refresh();
        $this->prepareRecordingInfrastructure($callLog);
        $this->mirrorVideoUpgradeStatusToSiblingLeg($callLog, $data);

        if ($recordingWasActive && $this->isTerminalCallStatus($callLog->status)) {
            $this->recordingManager->stop($callLog);
            $callLog->refresh();
        } elseif ($callLog->recording_status === CallRecordingStatus::Completed) {
            $this->recordingManager->queueSync($callLog);
        }

        $callLog = $this->recordingManager->reconcileCompletedRecordingMetadata($callLog);

        Log::info('Call log updated.', [
            'call_log_id' => $callLog->public_id,
            'caller_number' => $callLog->caller_number,
            'callee_number' => $callLog->callee_number,
            'freeswitch_uuid' => $callLog->freeswitch_uuid,
            'status' => $callLog->status instanceof \BackedEnum ? $callLog->status->value : $callLog->status,
        ]);

        return CallLogResource::make($callLog->load($this->callLogRelations()));
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

    private function isTerminalCallStatus(CallStatus|string|null $status): bool
    {
        $value = $status instanceof \BackedEnum ? $status->value : $status;

        return in_array($value, [
            CallStatus::Completed->value,
            CallStatus::Busy->value,
            CallStatus::Failed->value,
            CallStatus::NoAnswer->value,
            CallStatus::Canceled->value,
        ], true);
    }

    /**
     * @param  Collection<int, CallLog>  $callLogs
     * @return Collection<int, CallLog>
     */
    private function deduplicateCallLogs(Collection $callLogs): Collection
    {
        $recordingFields = [
            'recording_url', 'recording_id', 'recording_uuid', 'recording_media_type',
            'recording_container', 'recording_file_path', 'recording_file_name',
            'recording_duration', 'recording_size', 'recording_status',
            'recording_started_at', 'recording_ended_at',
        ];

        return $callLogs
            ->groupBy(fn (CallLog $callLog): string => $callLog->freeswitch_uuid ?? $callLog->public_id)
            ->map(function (Collection $group) use ($recordingFields): CallLog {
                $winner = $group->sortByDesc(fn (CallLog $callLog): int => $this->callLogDisplayPriority($callLog))->first();

                // The dedup keeps only one row per call, but a losing row can
                // still carry the only completed recording for that call (a
                // second call_logs write for the same freeswitch_uuid, e.g.
                // from a leg update, may not have copied recording_* over).
                // Merge any recording data the winner is missing so it is
                // never silently dropped from the Calls tab.
                if ($winner->recording_status === null) {
                    $donor = $group->first(fn (CallLog $callLog): bool => $callLog->recording_status !== null);

                    if ($donor !== null) {
                        foreach ($recordingFields as $field) {
                            $winner->setAttribute($field, $donor->getAttribute($field));
                        }
                    }
                }

                return $winner;
            })
            ->sortByDesc(fn (CallLog $callLog): int => $callLog->created_at?->getTimestamp() ?? 0)
            ->values();
    }

    private function callLogDisplayPriority(CallLog $callLog): int
    {
        $priority = 0;

        if ($callLog->recording_status === CallRecordingStatus::Completed) {
            $priority += 300;

            if ($callLog->recording_file_path !== null
                && Storage::disk(config('telephony.call_recordings.disk'))->exists($callLog->recording_file_path)) {
                $priority += 100;
            }
        } elseif ($callLog->recording_status === CallRecordingStatus::Recording
            || $callLog->recording_status === CallRecordingStatus::Starting) {
            $priority += 200;
        } elseif ($callLog->recording_status === CallRecordingStatus::Failed
            || $callLog->recording_status === CallRecordingStatus::Orphaned) {
            $priority += 50;
        }

        if ($this->isTerminalCallStatus($callLog->status)) {
            $priority += 25;
        }

        return $priority;
    }

    /** @return list<string> */
    private function callLogRelations(): array
    {
        return [
            'callerExtension.dialableNumber',
            'callerExtension.user',
            'callerExtension.fallbackExtension',
            'calleeExtension.dialableNumber',
            'calleeExtension.user',
            'calleeExtension.fallbackExtension',
        ];
    }

    /**
     * Each party in a call independently creates its own call_logs row when
     * the call starts (see stores/calling.ts handleCallStateTransition) - a
     * physical call is two separate rows with two DIFFERENT freeswitch_uuid
     * values (FreeSWITCH's B2BUA assigns each bridged leg its own channel
     * UUID; confirmed empirically, not just in theory - two rows for the
     * same live test call had two different UUIDs). The audio-to-video
     * upgrade accept handshake (see stores/calling.ts
     * handleVideoUpgradeStatus) relies on both parties' independent polling
     * loops seeing the same video_upgrade_status value - writing it to only
     * the row the request came in on left the other party's row, and
     * therefore their poll, completely unaware anything happened.
     *
     * There is no shared key between the two rows at all, so find the
     * sibling leg the same way FreeSwitchCallUuidSynchronizer::
     * resolveCallLog() already does: matching caller/callee number (either
     * order - each party's own row has itself and the other party in
     * opposite caller/callee slots) within a short window of this row's
     * creation.
     *
     * @param  array<string, mixed>  $data
     */
    private function mirrorVideoUpgradeStatusToSiblingLeg(CallLog $callLog, array $data): void
    {
        if (! array_key_exists('video_upgrade_status', $data)) {
            return;
        }

        // Not scoped to organization_id: the two parties' rows can belong to
        // different organizations entirely (each party's call log lives in
        // their own org/workspace context) - confirmed empirically on a real
        // test call (org 6 vs org 5 for the same physical call).
        CallLog::query()
            ->whereKeyNot($callLog->getKey())
            ->whereBetween('created_at', [
                $callLog->created_at->copy()->subMinute(),
                $callLog->created_at->copy()->addMinute(),
            ])
            ->where(function ($query) use ($callLog): void {
                $query->where([
                    'caller_number' => $callLog->caller_number,
                    'callee_number' => $callLog->callee_number,
                ])->orWhere([
                    'caller_number' => $callLog->callee_number,
                    'callee_number' => $callLog->caller_number,
                ]);
            })
            ->update(['video_upgrade_status' => $data['video_upgrade_status']]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stripDuplicateFreeSwitchUuid(array $data, ?CallLog $currentCallLog = null): array
    {
        $freeswitchUuid = $data['freeswitch_uuid'] ?? null;

        if (! is_string($freeswitchUuid) || trim($freeswitchUuid) === '') {
            return $data;
        }

        $owner = CallLog::query()
            ->where('freeswitch_uuid', trim($freeswitchUuid))
            ->when(
                $currentCallLog !== null,
                fn ($query) => $query->whereKeyNot($currentCallLog->getKey()),
            )
            ->select(['id', 'public_id'])
            ->first();

        if ($owner === null) {
            return $data;
        }

        unset($data['freeswitch_uuid']);

        Log::warning('Ignoring duplicate FreeSWITCH UUID on call log write.', [
            'freeswitch_uuid' => $freeswitchUuid,
            'existing_call_log_id' => $owner->public_id,
            'current_call_log_id' => $currentCallLog?->public_id,
        ]);

        return $data;
    }
}
