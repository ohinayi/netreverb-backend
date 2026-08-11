<?php

namespace App\Enums;

enum ConferenceRecordingStatus: string
{
    case Starting = 'starting';
    case Recording = 'recording';
    case Stopping = 'stopping';
    case Combining = 'combining';
    case Completed = 'completed';
    case Failed = 'failed';
    case Orphaned = 'orphaned';
}
