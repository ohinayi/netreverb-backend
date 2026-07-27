<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CallRecordingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FinalizeCallRecordingUploadRequest;
use App\Http\Requests\Api\V1\StartCallRecordingRequest;
use App\Http\Requests\Api\V1\UploadCallRecordingChunkRequest;
use App\Http\Resources\Api\V1\CallLogResource;
use App\Models\CallLog;
use App\Models\Organization;
use App\Services\Auditing\AuditLogger;
use App\Services\CallRecordings\CallRecordingManager;
use App\Services\CallRecordings\DirectVideoRecordingUploadManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CallRecordingController extends Controller
{
    public function __construct(
        private readonly CallRecordingManager $recordingManager,
        private readonly DirectVideoRecordingUploadManager $uploadManager,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function start(
        StartCallRecordingRequest $request,
        Organization $organization,
        CallLog $callLog,
    ): CallLogResource {
        Gate::authorize('update', $callLog);

        $callUuid = $request->string('recording_uuid')->toString();

        if ($callUuid === '') {
            $callUuid = $request->string('freeswitch_uuid')->toString();
        }

        if ($callUuid === '') {
            $callUuid = (string) ($callLog->freeswitch_uuid ?? '');
        }

        $requestedMode = $request->string('recording_mode')->toString() ?: null;
        $requestedContainer = $request->string('recording_container')->toString() ?: null;
        $requestedMimeType = $request->string('recording_mime_type')->toString() ?: null;
        $profile = $this->recordingManager->requestedProfileFor(
            $callLog,
            $requestedMode,
            $requestedContainer,
        );

        if ($callUuid === '') {
            throw ValidationException::withMessages([
                'recording_uuid' => 'A FreeSWITCH UUID is required before recording can start.',
            ]);
        }

        $this->recordingManager->start(
            $callLog,
            $callUuid,
            $profile,
        );

        if ($this->recordingManager->usesClientVideoUploadProfile($profile)) {
            $this->uploadManager->createOrRefreshSession(
                $callLog->fresh(),
                $profile->container,
                $requestedMimeType,
            );
        }

        return CallLogResource::make($callLog->fresh([
            'callerExtension.dialableNumber',
            'callerExtension.user',
            'callerExtension.fallbackExtension',
            'calleeExtension.dialableNumber',
            'calleeExtension.user',
            'calleeExtension.fallbackExtension',
            'recordingUpload',
        ]));
    }

    public function stop(
        Organization $organization,
        CallLog $callLog,
    ): CallLogResource {
        Gate::authorize('update', $callLog);

        $this->recordingManager->stop($callLog);

        return CallLogResource::make($callLog->fresh([
            'callerExtension.dialableNumber',
            'callerExtension.user',
            'callerExtension.fallbackExtension',
            'calleeExtension.dialableNumber',
            'calleeExtension.user',
            'calleeExtension.fallbackExtension',
            'recordingUpload',
        ]));
    }

    public function uploadChunk(
        UploadCallRecordingChunkRequest $request,
        Organization $organization,
        CallLog $callLog,
    ): CallLogResource {
        Gate::authorize('update', $callLog);

        $this->uploadManager->appendChunk(
            $callLog,
            (int) $request->integer('sequence'),
            $request->file('chunk'),
        );

        return CallLogResource::make($callLog->fresh([
            'callerExtension.dialableNumber',
            'callerExtension.user',
            'callerExtension.fallbackExtension',
            'calleeExtension.dialableNumber',
            'calleeExtension.user',
            'calleeExtension.fallbackExtension',
            'recordingUpload',
        ]));
    }

    public function finalizeUpload(
        FinalizeCallRecordingUploadRequest $request,
        Organization $organization,
        CallLog $callLog,
    ): CallLogResource {
        Gate::authorize('update', $callLog);

        $this->uploadManager->finalize(
            $callLog,
            $request->date('ended_at'),
        );

        return CallLogResource::make($callLog->fresh([
            'callerExtension.dialableNumber',
            'callerExtension.user',
            'callerExtension.fallbackExtension',
            'calleeExtension.dialableNumber',
            'calleeExtension.user',
            'calleeExtension.fallbackExtension',
            'recordingUpload',
        ]));
    }

    public function show(
        Request $request,
        Organization $organization,
        CallLog $callLog,
    ): BinaryFileResponse {
        Gate::authorize('view', $callLog);

        if ($callLog->recording_file_path === null || $callLog->recording_file_path === '') {
            abort(404);
        }

        $disk = Storage::disk(config('telephony.call_recordings.disk'));

        if (! $disk->exists($callLog->recording_file_path)) {
            if ($callLog->recording_status === CallRecordingStatus::Completed) {
                $this->recordingManager->queueSync($callLog);
            }

            abort(404);
        }

        $this->auditLogger->record(
            $request,
            $request->user(),
            $organization,
            'call.recording.accessed',
            $callLog,
            after: ['recording_file_name' => $callLog->recording_file_name],
        );

        return response()->file(
            $disk->path($callLog->recording_file_path),
            [
                'Content-Type' => $disk->mimeType($callLog->recording_file_path) ?? 'audio/wav',
            ],
        );
    }

    public function destroy(
        Organization $organization,
        CallLog $callLog,
    ): JsonResponse {
        Gate::authorize('update', $callLog);

        $this->recordingManager->delete($callLog);

        return response()->json([
            'message' => 'Call recording deleted.',
        ]);
    }
}
