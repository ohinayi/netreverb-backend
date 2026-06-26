<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StartCallRecordingRequest;
use App\Http\Resources\Api\V1\CallLogResource;
use App\Models\CallLog;
use App\Models\Organization;
use App\Services\CallRecordings\CallRecordingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CallRecordingController extends Controller
{
    public function __construct(
        private readonly CallRecordingManager $recordingManager,
    ) {}

    public function start(
        StartCallRecordingRequest $request,
        Organization $organization,
        CallLog $callLog,
    ): CallLogResource {
        Gate::authorize('update', $callLog);

        $this->recordingManager->start(
            $callLog,
            $request->string('recording_uuid')->toString(),
        );

        return CallLogResource::make($callLog->fresh(['callerExtension.dialableNumber', 'calleeExtension.dialableNumber']));
    }

    public function stop(
        Organization $organization,
        CallLog $callLog,
    ): CallLogResource {
        Gate::authorize('update', $callLog);

        $this->recordingManager->stop($callLog);

        return CallLogResource::make($callLog->fresh(['callerExtension.dialableNumber', 'calleeExtension.dialableNumber']));
    }

    public function show(
        Organization $organization,
        CallLog $callLog,
    ): BinaryFileResponse {
        Gate::authorize('view', $callLog);

        if ($callLog->recording_file_path === null || $callLog->recording_file_path === '') {
            abort(404);
        }

        $disk = Storage::disk(config('telephony.call_recordings.disk'));

        if (! $disk->exists($callLog->recording_file_path)) {
            abort(404);
        }

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
