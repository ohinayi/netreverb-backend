<?php

namespace App\Enums;

enum ConferenceParticipantStatus: string
{
    case Invited = 'invited';
    case Joined = 'joined';
    case Left = 'left';
    case Removed = 'removed';
}
