<?php

namespace App\Services\CallRecordings;

use App\Contracts\Recordings\CallRecordingStorage;
use App\Contracts\Telephony\FreeSwitchCallGateway;
use App\Data\CallRecordingProfile;
use App\Enums\CallRecordingMediaType;
use App\Enums\CallRecordingStatus;
use App\Enums\CallSessionType;
use App\Enums\CallStatus;
use App\Exceptions\FreeSwitchRecordingException;
use App\Jobs\AnnounceCallRecordingStart;
use App\Jobs\SyncCallRecordingFromVps;
use App\Models\CallLog;
use App\Models\CallRecordingUpload;
use App\Models\Organization;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CallRecordingManager
{
    public function __construct(
        private readonly CallRecordingStorage $storage,
        private readonly FreeSwitchCallGateway $gateway,
        private readonly Dispatcher $dispatcher,
        private readonly DirectVideoRecordingMuxer $directVideoRecordingMuxer,
    ) {}

    public function start(CallLog $callLog, string $callUuid, ?CallRecordingProfile $profile = null): ?CallLog
    {
        $shouldStartRecording = false;
        $profile ??= $this->recordingProfileFor($callLog);

        $callLog = DB::transaction(function () use ($callLog, $callUuid, $profile, &$shouldStartRecording): ?CallLog {
            $callLog = CallLog::query()
                ->lockForUpdate()
                ->findOrFail($callLog->id);
            $callLog->loadMissing('organization');

            if ($callLog->recording_status === CallRecordingStatus::Starting
                || $callLog->recording_status === CallRecordingStatus::Recording) {
                $shouldStartRecording = $callLog->recording_status === CallRecordingStatus::Starting;

                return $callLog;
            }

            $callLog = $this->prepareLockedCallLog($callLog, $callUuid, $profile, now());
            $callLog->forceFill([
                'recording_status' => CallRecordingStatus::Starting,
                'recording_started_at' => $callLog->recording_started_at ?? now(),
                'recording_ended_at' => null,
                'recording_duration' => null,
                'recording_size' => null,
            ])->save();

            $shouldStartRecording = true;

            return $callLog->refresh();
        }, attempts: 3);

        if ($callLog === null || ! $shouldStartRecording) {
            return null;
        }

        try {
            $absolutePath = $this->storage->absolutePath($callLog);
            $this->storage->ensureDirectoryExists($callLog->recording_file_path ?? '');

            if ($this->usesClientVideoUploadProfile($profile)) {
                $audioProfile = $this->clientVideoAudioProfileFor($callLog);
                $audioAbsolutePath = $this->directVideoRecordingMuxer->audioSidecarAbsolutePath($callLog);
                $this->storage->ensureDirectoryExists($this->directVideoRecordingMuxer->audioSidecarRelativePath($callLog));
                Log::info('Starting direct video call server audio sidecar recording.', [
                    'call_log_id' => $callLog->id,
                    'public_id' => $callLog->public_id,
                    'recording_id' => $callLog->recording_id,
                    'recording_uuid' => $callUuid,
                    'recording_path' => $audioAbsolutePath,
                    'recording_media_type' => $audioProfile->mediaType->value,
                    'recording_container' => $audioProfile->container,
                ]);

                $this->gateway->startRecording($callUuid, $audioAbsolutePath, $audioProfile);
                $callLog->forceFill([
                    'recording_status' => CallRecordingStatus::Recording,
                ])->save();
                $this->dispatcher->dispatch(new AnnounceCallRecordingStart($callLog->id, $callUuid));

                return $callLog->refresh();
            }

            Log::info('Starting FreeSWITCH call recording.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'recording_id' => $callLog->recording_id,
                'recording_uuid' => $callUuid,
                'recording_path' => $absolutePath,
                'recording_media_type' => $profile->mediaType->value,
                'recording_container' => $profile->container,
            ]);

            $this->gateway->startRecording($callUuid, $absolutePath, $profile);
            $callLog->forceFill([
                'recording_status' => CallRecordingStatus::Recording,
            ])->save();
            $this->dispatcher->dispatch(new AnnounceCallRecordingStart($callLog->id, $callUuid));
        } catch (Throwable $exception) {
            $callLog->forceFill([
                'recording_status' => CallRecordingStatus::Failed,
            ])->save();

            Log::warning('FreeSWITCH call recording start failed.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'recording_id' => $callLog->recording_id,
                'recording_uuid' => $callUuid,
                'recording_file_path' => $callLog->recording_file_path,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return $callLog->refresh();
    }

    public function announceStart(CallLog $callLog, string $callUuid): void
    {
        $organization = $callLog->organization;

        if (! $organization instanceof Organization || ! $organization->shouldAnnounceCallRecording()) {
            return;
        }

        $audioPath = $organization->callRecordingAnnouncementAudioPath();

        if ($audioPath === null) {
            Log::warning('Call recording announcement is enabled but no audio path is configured.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'organization_id' => $organization->public_id,
            ]);

            return;
        }

        try {
            $this->gateway->announceRecordingStart(
                $callUuid,
                $audioPath,
                $organization->callRecordingAnnouncementTarget()->value,
            );
        } catch (Throwable $exception) {
            Log::warning('FreeSWITCH call recording announcement failed.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'organization_id' => $organization->public_id,
                'recording_uuid' => $callUuid,
                'announcement_audio_path' => $audioPath,
                'announcement_target' => $organization->callRecordingAnnouncementTarget()->value,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function stop(CallLog $callLog): ?CallLog
    {
        $callLog = DB::transaction(function () use ($callLog): ?CallLog {
            $callLog = CallLog::query()
                ->lockForUpdate()
                ->findOrFail($callLog->id);
            $callLog->loadMissing('organization');

            if ($callLog->recording_status !== CallRecordingStatus::Starting
                && $callLog->recording_status !== CallRecordingStatus::Recording) {
                return null;
            }

            return $callLog->refresh();
        }, attempts: 3);

        if ($callLog === null || $callLog->recording_uuid === null) {
            return $callLog;
        }

        $absolutePath = $this->storage->absolutePath($callLog);
        $startedAt = $callLog->recording_started_at ?? $callLog->created_at ?? now();
        $endedAt = now();
        $profile = $this->activeRecordingProfileFor($callLog);

        if ($this->usesClientVideoUploadCallLog($callLog)) {
            $audioProfile = $this->clientVideoAudioProfileFor($callLog);
            $audioAbsolutePath = $this->directVideoRecordingMuxer->audioSidecarAbsolutePath($callLog);

            try {
                Log::info('Stopping direct video call server audio sidecar recording.', [
                    'call_log_id' => $callLog->id,
                    'public_id' => $callLog->public_id,
                    'recording_id' => $callLog->recording_id,
                    'recording_uuid' => $callLog->recording_uuid,
                    'recording_path' => $audioAbsolutePath,
                    'recording_media_type' => $audioProfile->mediaType->value,
                    'recording_container' => $audioProfile->container,
                ]);

                $this->gateway->stopRecording($callLog->recording_uuid, $audioAbsolutePath, $audioProfile);
            } catch (FreeSwitchRecordingException $exception) {
                if (! $this->sessionAlreadyEnded($exception)) {
                    Log::warning('Direct video call server audio sidecar stop failed.', [
                        'call_log_id' => $callLog->id,
                        'public_id' => $callLog->public_id,
                        'recording_id' => $callLog->recording_id,
                        'recording_uuid' => $callLog->recording_uuid,
                        'recording_file_path' => $this->directVideoRecordingMuxer->audioSidecarRelativePath($callLog),
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                }
            } catch (Throwable $exception) {
                Log::warning('Direct video call server audio sidecar stop failed.', [
                    'call_log_id' => $callLog->id,
                    'public_id' => $callLog->public_id,
                    'recording_id' => $callLog->recording_id,
                    'recording_uuid' => $callLog->recording_uuid,
                    'recording_file_path' => $this->directVideoRecordingMuxer->audioSidecarRelativePath($callLog),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }

            $this->queueSync($callLog, $this->directVideoRecordingMuxer->audioSidecarRelativePath($callLog));

            return $callLog->refresh();
        }

        try {
            Log::info('Stopping FreeSWITCH call recording.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'recording_id' => $callLog->recording_id,
                'recording_uuid' => $callLog->recording_uuid,
                'recording_path' => $absolutePath,
                'recording_media_type' => $profile->mediaType->value,
                'recording_container' => $profile->container,
            ]);

            $this->gateway->stopRecording($callLog->recording_uuid, $absolutePath, $profile);
            $this->finalizeStoppedRecording($callLog, $startedAt, $endedAt);
        } catch (FreeSwitchRecordingException $exception) {
            if ($this->sessionAlreadyEnded($exception)) {
                Log::warning('FreeSWITCH recording stop was requested after the session ended. Finalizing metadata and syncing from the VPS.', [
                    'call_log_id' => $callLog->id,
                    'public_id' => $callLog->public_id,
                    'recording_id' => $callLog->recording_id,
                    'recording_uuid' => $callLog->recording_uuid,
                    'recording_file_path' => $callLog->recording_file_path,
                    'message' => $exception->getMessage(),
                ]);

                $this->finalizeStoppedRecording($callLog, $startedAt, $endedAt);

                return $callLog->refresh();
            }

            $callLog->forceFill([
                'recording_status' => CallRecordingStatus::Orphaned,
            ])->save();

            Log::warning('FreeSWITCH call recording stop failed.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'recording_id' => $callLog->recording_id,
                'recording_uuid' => $callLog->recording_uuid,
                'recording_file_path' => $callLog->recording_file_path,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            $callLog->forceFill([
                'recording_status' => CallRecordingStatus::Orphaned,
            ])->save();

            Log::warning('FreeSWITCH call recording stop failed.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'recording_id' => $callLog->recording_id,
                'recording_uuid' => $callLog->recording_uuid,
                'recording_file_path' => $callLog->recording_file_path,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return $callLog->refresh();
    }

    public function queueSync(CallLog $callLog, ?string $relativePath = null): void
    {
        $pathToSync = $relativePath;

        if ($pathToSync === null || $pathToSync === '') {
            $pathToSync = $callLog->recording_file_path;
        }

        if ($pathToSync === null || $pathToSync === '') {
            return;
        }

        $this->dispatcher->dispatchAfterResponse(new SyncCallRecordingFromVps($callLog->id, $pathToSync));
    }

    public function reconcileCompletedRecordingMetadata(CallLog $callLog): CallLog
    {
        if ($callLog->recording_file_path === null || $callLog->recording_file_path === '') {
            return $callLog;
        }

        if (! $this->isTerminalCallStatus($callLog->status)) {
            return $callLog;
        }

        if (! $this->storage->exists($callLog)) {
            return $callLog;
        }

        if ($callLog->recording_status === CallRecordingStatus::Completed
            && $callLog->recording_size !== null
            && $callLog->recording_duration !== null) {
            return $callLog;
        }

        $endedAt = $callLog->recording_ended_at
            ?? $callLog->ended_at
            ?? $callLog->updated_at
            ?? now();
        $startedAt = $callLog->recording_started_at
            ?? $callLog->created_at
            ?? $endedAt;

        $callLog->forceFill([
            'recording_status' => CallRecordingStatus::Completed,
            'recording_ended_at' => $endedAt,
            'recording_duration' => max(0, $startedAt->diffInSeconds($endedAt)),
            'recording_size' => $this->storage->size($callLog),
        ])->save();

        return $callLog->refresh();
    }

    public function delete(CallLog $callLog): void
    {
        $this->storage->delete($callLog);
        CallRecordingUpload::query()->whereBelongsTo($callLog)->delete();
        $callLog->forceFill([
            'recording_status' => CallRecordingStatus::Orphaned,
            'recording_id' => null,
            'recording_file_path' => null,
            'recording_file_name' => null,
            'recording_duration' => null,
            'recording_size' => null,
            'recording_url' => null,
            'recording_uuid' => null,
            'recording_started_at' => null,
            'recording_ended_at' => null,
        ])->save();
    }

    public function cleanup(int $retentionDays): int
    {
        $deletedCount = 0;

        CallLog::query()
            ->with('organization')
            ->whereNotNull('recording_file_path')
            ->whereNull('deleted_at')
            ->oldest('id')
            ->chunkById(100, function ($callLogs) use ($retentionDays, &$deletedCount): void {
                foreach ($callLogs as $callLog) {
                    $effectiveRetentionDays = $callLog->organization?->recordingRetentionDays()
                        ?? max(1, $retentionDays);
                    $cutoff = now()->subDays($effectiveRetentionDays);
                    $shouldDelete = false;

                    if (! $this->storage->exists($callLog)) {
                        $shouldDelete = true;
                    } elseif (in_array($callLog->recording_status, [
                        CallRecordingStatus::Failed,
                        CallRecordingStatus::Orphaned,
                    ], true)
                        && $callLog->created_at !== null
                        && $callLog->created_at->lt($cutoff)) {
                        $shouldDelete = true;
                    } elseif ($callLog->recording_status === CallRecordingStatus::Completed
                        && $callLog->created_at !== null
                        && $callLog->created_at->lt($cutoff)) {
                        $shouldDelete = true;
                    }

                    if (! $shouldDelete) {
                        continue;
                    }

                    $this->delete($callLog);
                    $deletedCount++;
                }
            });

        return $deletedCount;
    }

    public function reconcileMissingFiles(): int
    {
        $reconciledCount = 0;

        CallLog::query()
            ->whereNotNull('recording_file_path')
            ->whereNull('deleted_at')
            ->oldest('id')
            ->chunkById(100, function ($callLogs) use (&$reconciledCount): void {
                foreach ($callLogs as $callLog) {
                    if ($this->storage->exists($callLog)) {
                        continue;
                    }

                    $this->delete($callLog);
                    $reconciledCount++;
                }
            });

        return $reconciledCount;
    }

    public function prepare(CallLog $callLog, string $callUuid, ?CallRecordingProfile $profile = null): CallLog
    {
        $profile ??= $this->recordingProfileFor($callLog);

        return DB::transaction(function () use ($callLog, $callUuid, $profile): CallLog {
            $lockedCallLog = CallLog::query()
                ->lockForUpdate()
                ->findOrFail($callLog->id);
            $lockedCallLog->loadMissing('organization');

            if ($this->hasPreparedRecordingMetadata($lockedCallLog, $callUuid, $profile)) {
                return $lockedCallLog->refresh();
            }

            $preparedCallLog = $this->prepareLockedCallLog($lockedCallLog, $callUuid, $profile, now());
            $preparedCallLog->save();
            $this->storage->ensureDirectoryExists($preparedCallLog->recording_file_path ?? '');

            return $preparedCallLog->refresh();
        }, attempts: 3);
    }

    public function requestedProfileFor(
        CallLog $callLog,
        ?string $mode = null,
        ?string $container = null,
    ): CallRecordingProfile {
        if (($callLog->media_type?->value ?? $callLog->media_type) !== 'video') {
            return $this->recordingProfileFor($callLog);
        }

        if ($this->shouldUseClientVideoUpload($callLog, $mode)) {
            return new CallRecordingProfile(
                sessionType: $callLog->session_type ?? CallSessionType::Direct,
                mediaType: CallRecordingMediaType::Video,
                container: $container ?: $this->videoRecordingContainerFor($callLog, 'webm'),
            );
        }

        return new CallRecordingProfile(
            sessionType: $callLog->session_type ?? CallSessionType::Direct,
            mediaType: CallRecordingMediaType::Video,
            container: $container ?: $this->videoRecordingContainerFor($callLog, 'mp4'),
        );
    }

    public function shouldUseClientVideoUpload(?CallLog $callLog = null, ?string $mode = null): bool
    {
        $strategy = $this->videoRecordingStrategyFor($callLog);

        return ($mode !== null && $mode === 'client_chunks')
            || $strategy === 'client_chunks';
    }

    public function usesClientVideoUploadCallLog(CallLog $callLog): bool
    {
        return ($callLog->recording_media_type?->value ?? $callLog->recording_media_type) === CallRecordingMediaType::Video->value
            && $this->shouldUseClientVideoUpload($callLog);
    }

    public function usesClientVideoUploadProfile(CallRecordingProfile $profile): bool
    {
        return $profile->mediaType === CallRecordingMediaType::Video
            && $this->videoRecordingStrategyForSessionType($profile->sessionType) === 'client_chunks';
    }

    private function prepareLockedCallLog(
        CallLog $callLog,
        string $callUuid,
        CallRecordingProfile $profile,
        Carbon $recordedAt,
    ): CallLog {
        $location = $this->storage->buildLocation($callLog, $callUuid, $profile, $recordedAt);

        $callLog->forceFill([
            'recording_id' => $callLog->recording_id ?: (string) Str::ulid(),
            'recording_uuid' => $callUuid,
            'recording_media_type' => $profile->mediaType,
            'recording_container' => $profile->container,
            'recording_file_path' => $location->relativePath,
            'recording_file_name' => $location->fileName,
            'recording_url' => route('organizations.call-logs.recording.show', [
                'organization' => $callLog->organization?->public_id ?? $callLog->organization_id,
                'callLog' => $callLog->public_id,
            ]),
            'recording_started_at' => $callLog->recording_started_at ?? $recordedAt,
        ]);

        Log::info('Call recording metadata prepared.', [
            'call_log_id' => $callLog->id,
            'public_id' => $callLog->public_id,
            'recording_id' => $callLog->recording_id,
            'recording_uuid' => $callUuid,
            'recording_file_path' => $location->relativePath,
            'recording_file_name' => $location->fileName,
        ]);

        return $callLog;
    }

    private function recordingProfileFor(CallLog $callLog): CallRecordingProfile
    {
        if ($callLog->session_type === CallSessionType::Conference) {
            if ((string) ($callLog->media_type?->value ?? $callLog->media_type) === 'video'
                && $this->supportsConferenceVideoRecording()) {
                return new CallRecordingProfile(
                    sessionType: CallSessionType::Conference,
                    mediaType: CallRecordingMediaType::Video,
                    container: (string) config('telephony.webrtc.recording.conference_video_container', 'webm'),
                );
            }

            return new CallRecordingProfile(
                sessionType: CallSessionType::Conference,
                mediaType: CallRecordingMediaType::Audio,
                container: (string) config('telephony.webrtc.recording.conference_audio_container', 'wav'),
            );
        }

        if ((string) ($callLog->media_type?->value ?? $callLog->media_type) === 'video'
            && $this->supportsDirectVideoRecording()) {
            return new CallRecordingProfile(
                sessionType: CallSessionType::Direct,
                mediaType: CallRecordingMediaType::Video,
                container: (string) config('telephony.webrtc.recording.direct_video_container', 'mp4'),
            );
        }

        return new CallRecordingProfile(
            sessionType: CallSessionType::Direct,
            mediaType: CallRecordingMediaType::Audio,
            container: (string) config('telephony.webrtc.recording.direct_audio_container', 'wav'),
        );
    }

    private function supportsDirectVideoRecording(): bool
    {
        if (! (bool) config('telephony.webrtc.recording.direct_video_enabled', false)) {
            return false;
        }

        if ($this->videoRecordingStrategyForSessionType(CallSessionType::Direct) === 'client_chunks') {
            return true;
        }

        $startTemplate = config('telephony.webrtc.recording.direct_video_start_command_template');
        $stopTemplate = config('telephony.webrtc.recording.direct_video_stop_command_template');

        return is_string($startTemplate)
            && trim($startTemplate) !== ''
            && is_string($stopTemplate)
            && trim($stopTemplate) !== '';
    }

    private function supportsConferenceVideoRecording(): bool
    {
        if (! (bool) config('telephony.webrtc.recording.conference_video_enabled', false)) {
            return false;
        }

        return $this->videoRecordingStrategyForSessionType(CallSessionType::Conference) === 'client_chunks';
    }

    private function hasPreparedRecordingMetadata(
        CallLog $callLog,
        string $callUuid,
        CallRecordingProfile $profile,
    ): bool {
        return $callLog->recording_uuid === $callUuid
            && $callLog->recording_file_path !== null
            && $callLog->recording_file_path !== ''
            && $callLog->recording_file_name !== null
            && $callLog->recording_file_name !== ''
            && ($callLog->recording_media_type?->value ?? $callLog->recording_media_type) === $profile->mediaType->value
            && (string) $callLog->recording_container === $profile->container;
    }

    private function activeRecordingProfileFor(CallLog $callLog): CallRecordingProfile
    {
        if ($callLog->recording_media_type instanceof CallRecordingMediaType
            && is_string($callLog->recording_container)
            && $callLog->recording_container !== '') {
            return new CallRecordingProfile(
                sessionType: $callLog->session_type ?? CallSessionType::Direct,
                mediaType: $callLog->recording_media_type,
                container: $callLog->recording_container,
            );
        }

        return $this->recordingProfileFor($callLog);
    }

    private function finalizeStoppedRecording(CallLog $callLog, Carbon $startedAt, Carbon $endedAt): void
    {
        $recordingExistsLocally = $this->storage->exists($callLog);

        $callLog->forceFill([
            'recording_status' => CallRecordingStatus::Completed,
            'recording_ended_at' => $endedAt,
            'recording_duration' => max(0, $startedAt->diffInSeconds($endedAt)),
            'recording_size' => $recordingExistsLocally ? $this->storage->size($callLog) : null,
        ])->save();

        if (! $recordingExistsLocally) {
            Log::warning('Call recording completed before the file became available on the application disk.', [
                'call_log_id' => $callLog->id,
                'public_id' => $callLog->public_id,
                'recording_id' => $callLog->recording_id,
                'recording_uuid' => $callLog->recording_uuid,
                'recording_file_path' => $callLog->recording_file_path,
            ]);
        }

        $this->queueSync($callLog);
    }

    private function directVideoAudioProfile(): CallRecordingProfile
    {
        return new CallRecordingProfile(
            sessionType: CallSessionType::Direct,
            mediaType: CallRecordingMediaType::Audio,
            container: (string) config('telephony.webrtc.recording.direct_audio_container', 'wav'),
        );
    }

    private function clientVideoAudioProfileFor(CallLog $callLog): CallRecordingProfile
    {
        if ($callLog->session_type === CallSessionType::Conference) {
            return new CallRecordingProfile(
                sessionType: CallSessionType::Conference,
                mediaType: CallRecordingMediaType::Audio,
                container: (string) config('telephony.webrtc.recording.conference_audio_container', 'wav'),
            );
        }

        return $this->directVideoAudioProfile();
    }

    private function videoRecordingContainerFor(CallLog $callLog, string $default): string
    {
        if ($callLog->session_type === CallSessionType::Conference) {
            return (string) config('telephony.webrtc.recording.conference_video_container', $default);
        }

        return (string) config('telephony.webrtc.recording.direct_video_container', $default);
    }

    private function videoRecordingStrategyFor(?CallLog $callLog = null): string
    {
        return $this->videoRecordingStrategyForSessionType($callLog?->session_type ?? CallSessionType::Direct);
    }

    private function videoRecordingStrategyForSessionType(CallSessionType $sessionType): string
    {
        if ($sessionType === CallSessionType::Conference) {
            return (string) config('telephony.webrtc.recording.conference_video_strategy', 'client_chunks');
        }

        return (string) config('telephony.webrtc.recording.direct_video_strategy', 'freeswitch');
    }

    private function sessionAlreadyEnded(FreeSwitchRecordingException $exception): bool
    {
        return str_contains($exception->getMessage(), 'Cannot locate session!');
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
}
