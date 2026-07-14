<?php

namespace App\Contracts\Recordings;

use App\Data\CallRecordingLocation;
use App\Data\CallRecordingProfile;
use App\Models\CallLog;
use Carbon\CarbonInterface;

interface CallRecordingStorage
{
    public function buildLocation(
        CallLog $callLog,
        string $callUuid,
        CallRecordingProfile $profile,
        CarbonInterface $recordedAt,
    ): CallRecordingLocation;

    public function delete(CallLog $callLog): void;

    public function exists(CallLog $callLog): bool;

    public function size(CallLog $callLog): ?int;

    public function absolutePath(CallLog $callLog): string;

    public function ensureDirectoryExists(string $relativePath): void;
}
