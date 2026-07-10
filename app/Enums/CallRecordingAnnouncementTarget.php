<?php

namespace App\Enums;

enum CallRecordingAnnouncementTarget: string
{
    case Caller = 'caller';
    case Callee = 'callee';
    case Both = 'both';
}
