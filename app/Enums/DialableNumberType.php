<?php

namespace App\Enums;

enum DialableNumberType: string
{
    case Extension = 'extension';
    case Service = 'service';
}
