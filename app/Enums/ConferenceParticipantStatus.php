<?php

namespace App\Enums;

enum ConferenceParticipantStatus: string
{
    case Invited = 'invited';
    case Waiting = 'waiting';
    case Joined = 'joined';
    case Disconnected = 'disconnected';
    case Denied = 'denied';
    case Left = 'left';
    case Removed = 'removed';
}
