<?php

namespace App\Enums;

enum ExtensionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Disabled = 'disabled';
}
