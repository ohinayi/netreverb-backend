<?php

namespace App\Enums;

enum ConferenceRoomStatus: string
{
    case Active = 'active';
    case Ended = 'ended';
    case Expired = 'expired';
}
