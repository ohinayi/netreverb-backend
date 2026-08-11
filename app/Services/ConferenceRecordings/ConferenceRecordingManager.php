<?php

namespace App\Services\ConferenceRecordings;

use App\Contracts\Recordings\ConferenceRecordingStorage;
use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\ConferenceRecordingStatus;
use App\Enums\ConferenceRecordingTrackStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRecording;
use App\Models\ConferenceRoom;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ConferenceRecordingManager
{
    public function __construct(
        private readonly ConferenceRecordingStorage $storage,
        private readonly FreeSwitchConferenceGateway $gateway,
    ) {}

    public function start(ConferenceRoom $conferenceRoom): ?ConferenceRecording
    {
        $conferenceName = $conferenceRoom->sip_number;
        $shouldStartRecording = false;

        $recording = DB::transaction(function () use ($conferenceRoom, &$shouldStartRecording): ?ConferenceRecording {

            $conferenceRoom = ConferenceRoom::query()
                ->lockForUpdate()
                ->findOrFail($conferenceRoom->id);

            $activeRecording = ConferenceRecording::query()
                ->where('conference_room_id', $conferenceRoom->id)
                ->whereIn('status', [
                    ConferenceRecordingStatus::Starting,
                    ConferenceRecordingStatus::Recording,
                    ConferenceRecordingStatus::Stopping,
                ])
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($activeRecording !== null) {
                $shouldStartRecording = $activeRecording->status === ConferenceRecordingStatus::Starting;

                return $activeRecording;
            }

            $recordedAt = now();
            $callId = (string) Str::ulid();
            $location = $this->storage->buildLocation($conferenceRoom, $callId, $recordedAt);

            $recording = ConferenceRecording::query()->create([
                'conference_room_id' => $conferenceRoom->id,
                'recording_id' => (string) Str::ulid(),
                'room_id' => $conferenceRoom->room_id,
                'call_id' => $callId,
                'file_path' => $location->relativePath,
                'file_name' => $location->fileName,
                'status' => ConferenceRecordingStatus::Starting,
            ]);

            $shouldStartRecording = true;

            return $recording->refresh();
        }, attempts: 3);

        if ($recording === null || ! $shouldStartRecording) {
            return null;
        }

        try {
            $this->storage->ensureDirectoryExists($recording->file_path ?? '');
            $this->gateway->startRecording($conferenceName, $this->storage->absolutePath($recording));
            $recording->forceFill([
                'status' => ConferenceRecordingStatus::Recording,
            ])->save();
        } catch (Throwable $exception) {
            $recording->forceFill([
                'status' => ConferenceRecordingStatus::Failed,
            ])->save();

            Log::warning('FreeSWITCH conference recording start failed.', [
                'conference_room_id' => $recording->conference_room_id,
                'recording_id' => $recording->recording_id,
                'exception' => $exception::class,
            ]);
        }

        return $recording->refresh();
    }

    public function stop(ConferenceRoom $conferenceRoom): ?ConferenceRecording
    {
        $conferenceName = $conferenceRoom->sip_number;

        $recording = DB::transaction(function () use ($conferenceRoom): ?ConferenceRecording {
            $conferenceRoom = ConferenceRoom::query()
                ->lockForUpdate()
                ->findOrFail($conferenceRoom->id);

            $recording = ConferenceRecording::query()
                ->where('conference_room_id', $conferenceRoom->id)
                ->whereIn('status', [
                    ConferenceRecordingStatus::Starting,
                    ConferenceRecordingStatus::Recording,
                    ConferenceRecordingStatus::Stopping,
                ])
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($recording === null) {
                return null;
            }

            return $recording->refresh();
        }, attempts: 3);

        if ($recording === null) {
            return null;
        }

        try {
            $this->gateway->stopRecording($conferenceName, $this->storage->absolutePath($recording));
            $recording->forceFill([
                'status' => ConferenceRecordingStatus::Completed,
                'duration' => max(0, now()->diffInSeconds($recording->created_at)),
                'size' => $this->storage->size($recording),
            ])->save();
        } catch (Throwable $exception) {
            $recording->forceFill([
                'status' => ConferenceRecordingStatus::Orphaned,
            ])->save();

            Log::warning('FreeSWITCH conference recording stop failed.', [
                'conference_room_id' => $recording->conference_room_id,
                'recording_id' => $recording->recording_id,
                'exception' => $exception::class,
            ]);
        }

        return $recording->refresh();
    }

    public function delete(ConferenceRecording $recording): void
    {
        // LiveKit recordings live in the livekit_recordings S3 disk under
        // storage_key, not the FreeSWITCH-only local disk $this->storage
        // knows about — it would silently no-op for them, leaking the S3
        // file forever while the DB row disappears. Purge that path too.
        $this->deleteLiveKitFiles($recording);
        $this->storage->delete($recording);
        $recording->delete();
    }

    private function deleteLiveKitFiles(ConferenceRecording $recording): void
    {
        $disk = Storage::disk('livekit_recordings');

        if ($recording->storage_key) {
            $disk->delete($recording->storage_key);
        }

        foreach ($recording->tracks as $track) {
            if ($track->storage_key) {
                $disk->delete(array_filter([$track->storage_key, $track->manifestStorageKey()]));
            }
        }
    }

    /**
     * A LiveKit recording's file_path is set to the same S3 key as
     * storage_key (see LiveKitConferenceRecordingManager::start()), but
     * $this->storage->exists() only ever checks the FreeSWITCH local disk —
     * so for LiveKit rows it always returns false. Route existence checks
     * through the right disk per row instead of assuming FreeSWITCH.
     */
    private function existsOnStorage(ConferenceRecording $recording): bool
    {
        if ($recording->storage_key) {
            return Storage::disk('livekit_recordings')->exists($recording->storage_key);
        }

        return $this->storage->exists($recording);
    }

    public function cleanup(int $retentionDays): int
    {
        $cutoff = now()->subDays(max(1, $retentionDays));
        // A legitimate stop-and-combine should finish within minutes, not
        // days - use a much shorter staleness window than the retention
        // cutoff so a stuck row gets resolved on the next nightly run
        // instead of silently sitting there until it ages out.
        $staleProcessingCutoff = now()->subHours(2);
        $deletedCount = 0;

        ConferenceRecording::query()
            ->with(['conferenceRoom', 'tracks'])
            ->whereNull('deleted_at')
            ->oldest('id')
            ->chunkById(100, function ($recordings) use ($cutoff, $staleProcessingCutoff, &$deletedCount): void {
                foreach ($recordings as $recording) {
                    $conferenceRoom = $recording->conferenceRoom;
                    $shouldDelete = false;

                    // A LiveKit recording's storage_key is set the moment it
                    // starts, long before Egress finalizes and uploads the
                    // real S3 object — checking existence for Starting/
                    // Recording/Stopping/Combining rows would always read
                    // "missing" and wrongly orphan every in-progress
                    // recording on the next nightly run. Only a status that
                    // claims to already have a finished file can be orphaned
                    // by a missing file.
                    if ($recording->status === ConferenceRecordingStatus::Completed
                        && ! $this->existsOnStorage($recording)) {
                        $recording->forceFill([
                            'status' => ConferenceRecordingStatus::Orphaned,
                        ])->save();
                        $shouldDelete = true;
                    } elseif ($conferenceRoom === null) {
                        $recording->forceFill([
                            'status' => ConferenceRecordingStatus::Orphaned,
                        ])->save();
                        $shouldDelete = true;
                    } elseif ($conferenceRoom->status !== ConferenceRoomStatus::Active
                        && in_array($recording->status, [
                            ConferenceRecordingStatus::Starting,
                            ConferenceRecordingStatus::Recording,
                        ], true)) {
                        $recording->forceFill([
                            'status' => ConferenceRecordingStatus::Orphaned,
                        ])->save();
                        $shouldDelete = true;
                    } elseif ($recording->status === ConferenceRecordingStatus::Completed
                        && $recording->created_at !== null
                        && $recording->created_at->lt($cutoff)) {
                        $shouldDelete = true;
                    } elseif (in_array($recording->status, [
                        ConferenceRecordingStatus::Failed,
                        ConferenceRecordingStatus::Orphaned,
                    ], true)
                        && $recording->created_at !== null
                        && $recording->created_at->lt($cutoff)) {
                        $shouldDelete = true;
                    } elseif (in_array($recording->status, [
                        ConferenceRecordingStatus::Stopping,
                        ConferenceRecordingStatus::Combining,
                    ], true)
                        && $recording->created_at !== null
                        && $recording->created_at->lt($staleProcessingCutoff)) {
                        // A track's stopEgress() call can fail on a network
                        // blip, or its completion webhook can simply never
                        // arrive - either way maybeFinalizeRecording() then
                        // waits forever for a terminal track state that will
                        // never come. A queue worker crash mid-combine can
                        // strand a row in Combining the exact same way. Both
                        // previously sat here forever, invisible to every
                        // other branch above. Force resolution instead.
                        foreach ($recording->tracks as $track) {
                            if (! $track->status->isTerminal()) {
                                $track->forceFill(['status' => ConferenceRecordingTrackStatus::Failed])->save();
                            }
                        }
                        $recording->forceFill([
                            'status' => ConferenceRecordingStatus::Orphaned,
                        ])->save();
                        $shouldDelete = true;
                    }

                    if (! $shouldDelete) {
                        continue;
                    }

                    $this->deleteLiveKitFiles($recording);
                    $this->storage->delete($recording);
                    $recording->delete();
                    $deletedCount++;
                }
            });

        return $deletedCount;
    }
}
