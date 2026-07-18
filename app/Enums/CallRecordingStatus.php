<?php

namespace App\Enums;

enum CallRecordingStatus: string
{
    case Starting = 'starting';
    case Recording = 'recording';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Orphaned = 'orphaned';
}
