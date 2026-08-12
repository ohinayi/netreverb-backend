<?php

namespace App\Exceptions;

use RuntimeException;

class OAuthLoginException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }

    public static function forCode(string $errorCode): self
    {
        return new self($errorCode);
    }
}
