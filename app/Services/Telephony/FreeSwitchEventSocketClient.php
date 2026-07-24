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

    public function api(string $command, ?int $timeoutSeconds = null): string
    {
        $socket = $this->openSocket($timeoutSeconds);

        try {
            $this->consumeBanner($socket);
            $this->send($socket, 'auth '.$this->password);
            $authResponse = $this->readMessage($socket);

            if (! str_contains($authResponse['reply_text'] ?? '', '+OK')) {
                throw new RuntimeException('FreeSWITCH event socket authentication failed.');
            }

            $this->send($socket, 'api '.$command);

            $response = $this->readMessage($socket);

            return trim($response['body'] ?? $response['reply_text'] ?? '');
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param  array<int, string>  $eventNames
     * @return array<int, array{headers: array<string, string>, body: string, reply_text: string}>
     */
    public function events(array $eventNames, int $listenSeconds = 1): array
    {
        $socket = $this->openSocket();

        try {
            $this->consumeBanner($socket);
            $this->send($socket, 'auth '.$this->password);
            $authResponse = $this->readMessage($socket);

            if (! str_contains($authResponse['reply_text'] ?? '', '+OK')) {
                throw new RuntimeException('FreeSWITCH event socket authentication failed.');
            }

            $this->send($socket, 'event plain '.implode(' ', $eventNames));
            $subscribeResponse = $this->readMessage($socket);

            if (! str_contains($subscribeResponse['reply_text'] ?? '', '+OK')) {
                throw new RuntimeException('FreeSWITCH event socket subscription failed.');
            }

            $events = [];
            $deadline = microtime(true) + max(1, $listenSeconds);

            while (microtime(true) < $deadline) {
                $remaining = max(0.0, $deadline - microtime(true));
                $seconds = (int) floor($remaining);
                $microseconds = (int) (($remaining - $seconds) * 1_000_000);
                $read = [$socket];
                $write = [];
                $except = [];

                $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

                if ($ready === false) {
                    break;
                }

                if ($ready === 0) {
                    continue;
                }

                $message = $this->readMessage($socket);

                if ($message['headers'] === [] && $message['body'] === '' && $message['reply_text'] === '') {
                    continue;
                }

                $events[] = $message;
            }

            return $events;
        } finally {
            fclose($socket);
        }
    }

    /**
     * @return resource
     */
    private function openSocket(?int $timeoutSeconds = null)
    {
        $timeout = $timeoutSeconds ?? $this->timeoutSeconds;
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            $timeout,
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf(
                'Unable to connect to the FreeSWITCH event socket at %s:%d (%s).',
                $this->host,
                $this->port,
                $errstr,
            ));
        }

        stream_set_timeout($socket, $timeout);

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
    /**
     * @return array{headers: array<string, string>, body: string, reply_text: string}
     */
    private function readMessage($socket): array
    {
        $headers = [];

        while (! feof($socket)) {
            $line = fgets($socket);

            if ($line === false) {
                break;
            }

            $trimmedLine = trim($line);

            if ($trimmedLine === '') {
                break;
            }

            if (str_contains($line, ':')) {
                [$name, $value] = array_map('trim', explode(':', $line, 2));

                $headers[strtolower($name)] = $value;
            }
        }

        $contentLength = isset($headers['content-length']) ? (int) $headers['content-length'] : 0;
        $replyText = $headers['reply-text'] ?? '';
        $body = '';

        if ($contentLength <= 0) {
            return [
                'headers' => $headers,
                'body' => '',
                'reply_text' => $replyText,
            ];
        }

        while (strlen($body) < $contentLength && ! feof($socket)) {
            $chunk = fread($socket, $contentLength - strlen($body));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        return [
            'headers' => $headers,
            'body' => $body,
            'reply_text' => $replyText,
        ];
    }
}
