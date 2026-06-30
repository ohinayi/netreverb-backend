<?php

namespace App\Contracts\Recordings;

use App\Data\ConferenceRecordingLocation;
use App\Models\ConferenceRecording;
use App\Models\ConferenceRoom;
use Carbon\CarbonInterface;

interface ConferenceRecordingStorage
{
    public function buildLocation(
        ConferenceRoom $conferenceRoom,
        string $callId,
        CarbonInterface $recordedAt,
    ): ConferenceRecordingLocation;

    public function delete(ConferenceRecording $recording): void;

    public function exists(ConferenceRecording $recording): bool;

    public function size(ConferenceRecording $recording): ?int;

    public function absolutePath(ConferenceRecording $recording): string;

    public function ensureDirectoryExists(string $relativePath): void;
}
