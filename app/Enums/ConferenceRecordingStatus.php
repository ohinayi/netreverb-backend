<?php

namespace App\Enums;

enum ConferenceRecordingStatus: string
{
    case Starting = 'starting';
    case Recording = 'recording';
    case Completed = 'completed';
    case Failed = 'failed';
    case Orphaned = 'orphaned';
}
