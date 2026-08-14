<?php

namespace App\Enums;

enum ConferenceTranscriptStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Ready, self::Failed => true,
            default => false,
        };
    }
}
