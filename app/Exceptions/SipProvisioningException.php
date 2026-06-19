<?php

namespace App\Exceptions;

use RuntimeException;

class SipProvisioningException extends RuntimeException
{
    public static function databaseOperationFailed(): self
    {
        return new self('The SIP authentication store operation failed.');
    }
}
