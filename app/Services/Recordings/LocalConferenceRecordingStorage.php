<?php

namespace App\Services\Recordings;

use App\Contracts\Recordings\ConferenceRecordingStorage;
use App\Data\ConferenceRecordingLocation;
use App\Models\ConferenceRecording;
use App\Models\ConferenceRoom;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LocalConferenceRecordingStorage implements ConferenceRecordingStorage
{
    public function buildLocation(
        ConferenceRoom $conferenceRoom,
        string $callId,
        CarbonInterface $recordedAt,
    ): ConferenceRecordingLocation {
        $directory = $recordedAt->format('Y/m/d');
        $fileName = sprintf(
            '%s_%s_%s.wav',
            $conferenceRoom->room_id,
            $recordedAt->format('Ymd_His'),
            Str::of($callId)->replace('/', '-')->replace('\\', '-')->toString(),
        );
        $relativePath = $directory.'/'.$fileName;

        Storage::disk(config('telephony.recordings.disk'))->makeDirectory($directory);

        return new ConferenceRecordingLocation(
            relativePath: $relativePath,
            fileName: $fileName,
            absolutePath: Storage::disk(config('telephony.recordings.disk'))->path($relativePath),
        );
    }

    public function delete(ConferenceRecording $recording): void
    {
        if ($recording->file_path === null || $recording->file_path === '') {
            return;
        }

        Storage::disk(config('telephony.recordings.disk'))->delete($recording->file_path);
    }

    public function exists(ConferenceRecording $recording): bool
    {
        if ($recording->file_path === null || $recording->file_path === '') {
            return false;
        }

        return Storage::disk(config('telephony.recordings.disk'))->exists($recording->file_path);
    }

    public function size(ConferenceRecording $recording): ?int
    {
        if (! $this->exists($recording)) {
            return null;
        }

        return Storage::disk(config('telephony.recordings.disk'))->size($recording->file_path);
    }

    public function absolutePath(ConferenceRecording $recording): string
    {
        return Storage::disk(config('telephony.recordings.disk'))->path($recording->file_path);
    }
}
