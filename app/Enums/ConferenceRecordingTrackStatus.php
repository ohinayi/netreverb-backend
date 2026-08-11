<?php

namespace App\Enums;

enum ConferenceRecordingTrackStatus: string
{
    case Starting = 'starting';
    case Recording = 'recording';
    case Stopping = 'stopping';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            default => false,
        };
    }
}
