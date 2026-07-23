<?php

namespace App\Enums;

enum ConferenceParticipantKind: string
{
    case Primary = 'primary';
    case ScreenShare = 'screen_share';
}
