<?php

namespace App\Enums;

enum CallRecordingStatus: string
{
    case Starting = 'starting';
    case Recording = 'recording';
    case Completed = 'completed';
    case Failed = 'failed';
    case Orphaned = 'orphaned';
}
