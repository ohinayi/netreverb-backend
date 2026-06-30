<?php

namespace App\Exceptions;

use RuntimeException;

class FreeSwitchRecordingException extends RuntimeException
{
    public static function commandFailed(string $command, string $response): self
    {
        return new self(sprintf(
            'FreeSWITCH recording command failed. command="%s" response="%s"',
            $command,
            trim($response),
        ));
    }

    public static function fileMissingAfterStop(string $absolutePath): self
    {
        return new self(sprintf(
            'FreeSWITCH reported recording stop but no file was found at "%s".',
            $absolutePath,
        ));
    }
}
