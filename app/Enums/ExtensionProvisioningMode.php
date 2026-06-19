<?php

namespace App\Enums;

enum ExtensionProvisioningMode: string
{
    case Automatic = 'automatic';
    case Approval = 'approval';
    case InviteOnly = 'invite_only';
    case Manual = 'manual';
}
