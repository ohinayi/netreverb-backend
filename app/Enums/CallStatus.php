<?php

namespace App\Enums;

enum CallStatus: string
{
    case Ringing = 'ringing';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Busy = 'busy';
    case Failed = 'failed';
    case NoAnswer = 'no_answer';
    case Canceled = 'canceled';
}
