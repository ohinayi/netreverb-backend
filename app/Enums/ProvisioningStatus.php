<?php

namespace App\Enums;

enum ProvisioningStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Active = 'active';
    case Failed = 'failed';
    case Disabled = 'disabled';
}
