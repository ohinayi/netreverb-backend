<?php

namespace App\Services\ConferenceRecordings;

use App\Contracts\Recordings\ConferenceRecordingStorage;
use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\ConferenceRecordingStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRecording;
use App\Models\ConferenceRoom;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $this->storage->delete($recording);
        $recording->delete();
    }

    public function cleanup(int $retentionDays): int
    {
        $cutoff = now()->subDays(max(1, $retentionDays));
        $deletedCount = 0;

        ConferenceRecording::query()
            ->with('conferenceRoom')
            ->whereNull('deleted_at')
            ->oldest('id')
            ->chunkById(100, function ($recordings) use ($cutoff, &$deletedCount): void {
                foreach ($recordings as $recording) {
                    $conferenceRoom = $recording->conferenceRoom;
                    $shouldDelete = false;

                    if (! $this->storage->exists($recording)) {
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
                    }

                    if (! $shouldDelete) {
                        continue;
                    }

                    $this->storage->delete($recording);
                    $recording->delete();
                    $deletedCount++;
                }
            });

        return $deletedCount;
    }
}
