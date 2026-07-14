<?php

namespace App\Services\Recordings;

use App\Contracts\Recordings\CallRecordingStorage;
use App\Data\CallRecordingLocation;
use App\Data\CallRecordingProfile;
use App\Models\CallLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LocalCallRecordingStorage implements CallRecordingStorage
{
    public function buildLocation(
        CallLog $callLog,
        string $callUuid,
        CallRecordingProfile $profile,
        CarbonInterface $recordedAt,
    ): CallRecordingLocation {
        $directory = $recordedAt->format('Y/m/d');
        $fileName = sprintf(
            '%s_%s_%s.%s',
            $callLog->public_id,
            $recordedAt->format('Ymd_His'),
            Str::of($callUuid)->replace('/', '-')->replace('\\', '-')->toString(),
            $profile->container,
        );
        $relativePath = $directory.'/'.$fileName;

        $absolutePath = rtrim((string) config('telephony.call_recordings.base_path'), '/').'/'.$relativePath;

        return new CallRecordingLocation(
            relativePath: $relativePath,
            fileName: $fileName,
            absolutePath: $absolutePath,
        );
    }

    public function delete(CallLog $callLog): void
    {
        if ($callLog->recording_file_path === null || $callLog->recording_file_path === '') {
            return;
        }

        Storage::disk(config('telephony.call_recordings.disk'))->delete($callLog->recording_file_path);
    }

    public function exists(CallLog $callLog): bool
    {
        if ($callLog->recording_file_path === null || $callLog->recording_file_path === '') {
            return false;
        }

        return Storage::disk(config('telephony.call_recordings.disk'))->exists($callLog->recording_file_path);
    }

    public function size(CallLog $callLog): ?int
    {
        if (! $this->exists($callLog)) {
            return null;
        }

        return Storage::disk(config('telephony.call_recordings.disk'))->size($callLog->recording_file_path);
    }

    public function absolutePath(CallLog $callLog): string
    {
        return rtrim((string) config('telephony.call_recordings.base_path'), '/').'/'.$callLog->recording_file_path;
    }

    public function ensureDirectoryExists(string $relativePath): void
    {
        $directory = trim(dirname($relativePath), './');

        if ($directory === '') {
            return;
        }

        Storage::disk(config('telephony.call_recordings.disk'))->makeDirectory($directory);
    }
}
