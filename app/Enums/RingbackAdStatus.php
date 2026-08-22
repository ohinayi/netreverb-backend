<?php

namespace App\Enums;

enum RingbackAdStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
