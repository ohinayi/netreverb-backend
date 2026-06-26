<?php

namespace App\Services\Telephony;

use RuntimeException;

class FreeSwitchEventSocketClient
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $password,
        private readonly int $timeoutSeconds,
    ) {}

    public function api(string $command): string
    {
        $socket = $this->openSocket();

        try {
            $this->consumeBanner($socket);
            $this->send($socket, 'auth '.$this->password);
            $authResponse = $this->readResponse($socket);

            if (! str_contains($authResponse, '+OK')) {
                throw new RuntimeException('FreeSWITCH event socket authentication failed.');
            }

            $this->send($socket, 'api '.$command);

            return trim($this->readResponse($socket));
        } finally {
            fclose($socket);
        }
    }

    /**
     * @return resource
     */
    private function openSocket()
    {
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            $this->timeoutSeconds,
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf(
                'Unable to connect to the FreeSWITCH event socket at %s:%d (%s).',
                $this->host,
                $this->port,
                $errstr,
            ));
        }

        stream_set_timeout($socket, $this->timeoutSeconds);

        return $socket;
    }

    /**
     * @param  resource  $socket
     */
    private function consumeBanner($socket): void
    {
        while (! feof($socket)) {
            $line = fgets($socket);

            if ($line === false) {
                break;
            }

            if (trim($line) === '') {
                break;
            }
        }
    }

    /**
     * @param  resource  $socket
     */
    private function send($socket, string $command): void
    {
        fwrite($socket, $command."\n\n");
    }

    /**
     * @param  resource  $socket
     */
    private function readResponse($socket): string
    {
        $buffer = '';

        while (! feof($socket)) {
            $line = fgets($socket);

            if ($line === false) {
                break;
            }

            $buffer .= $line;

            if (str_ends_with($buffer, "\n\n") || str_ends_with($buffer, "\r\n\r\n")) {
                break;
            }
        }

        return $buffer;
    }
}
