<?php

namespace App\Enums;

enum CallLogParticipantStatus: string
{
    case Ringing = 'ringing';
    case Active = 'active';
    case Left = 'left';
    case Failed = 'failed';
}
