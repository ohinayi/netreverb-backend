<?php

namespace App\Enums;

enum ConferenceCaptionTrackStatus: string
{
    case Starting = 'starting';
    case Active = 'active';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case Failed = 'failed';
}
