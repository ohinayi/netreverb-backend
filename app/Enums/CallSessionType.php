<?php

namespace App\Enums;

enum CallSessionType: string
{
    case Direct = 'direct';
    case Conference = 'conference';
}
