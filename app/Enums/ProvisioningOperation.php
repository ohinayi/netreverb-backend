<?php

namespace App\Enums;

enum ProvisioningOperation: string
{
    case Upsert = 'upsert';
    case Delete = 'delete';
}
