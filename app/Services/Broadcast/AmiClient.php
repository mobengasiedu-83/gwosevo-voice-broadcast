<?php

namespace App\Services\Broadcast;

use RuntimeException;

class AmiClient
{
    private $socket = null;

    private string $buffer = '';

    public function connect(): void
    {
        $c = config('services.vitalpbx');
        if (! preg_match('/\A[A-Za-z0-9.:-]+\z/', $c['host'] ?? '') || (int) $c['port'] < 1 || (int) $c['port'] > 65535 || empty($c['username']) || empty($c['secret'])) {
            throw new RuntimeException('AMI configuration incomplete.');
        }
        $this->socket = @stream_socket_client('tcp://'.$c['host'].':'.(int) $c['port'], $errno, $error, 5);
        if (! $this->socket) {
            throw new RuntimeException('AMI connection failed.');
        }
        stream_set_timeout($this->socket, 5);
        $banner = fgets($this->socket, 1024);
        if (! $banner || ! str_starts_with($banner, 'Asterisk Call Manager/')) {
            throw new RuntimeException('Unexpected AMI banner.');
        }
        $this->send(['Action' => 'Login', 'ActionID' => 'vb-login', 'Username' => $c['username'], 'Secret' => $c['secret'], 'Events' => 'on']);
        stream_set_blocking($this->socket, false);
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            foreach ($this->poll() as $frame) {
                if (($frame['ActionID'] ?? '') === 'vb-login') {
                    if (($frame['Response'] ?? '') !== 'Success') {
                        throw new RuntimeException('AMI login rejected.');
                    }

                    return;
                }
            }
        }
        throw new RuntimeException('AMI login timed out.');
    }

    public static function encode(array $headers): string
    {
        $frame = '';
        foreach ($headers as $key => $values) {
            if (! preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/', $key)) {
                throw new RuntimeException('Invalid AMI header.');
            }
            foreach ((array) $values as $value) {
                if (! is_scalar($value) || preg_match('/[\r\n\x00]/', (string) $value)) {
                    throw new RuntimeException('Invalid AMI value.');
                }
                $frame .= $key.': '.$value."\r\n";
            }
        }

        return $frame."\r\n";
    }

    public function send(array $headers): void
    {
        $frame = self::encode($headers);
        $offset = 0;
        $deadline = microtime(true) + 5;
        while ($offset < strlen($frame)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('AMI write timeout; outcome uncertain.');
            }
            $read = null;
            $write = [$this->socket];
            $except = null;
            if (@stream_select($read, $write, $except, 0, 100000) === false) {
                throw new RuntimeException('AMI write select failed.');
            }
            if (! $write) {
                continue;
            }
            $n = @fwrite($this->socket, substr($frame, $offset));
            if ($n === false || $n === 0) {
                throw new RuntimeException('AMI write failed; outcome uncertain.');
            }
            $offset += $n;
        }
    }

    public function poll(): array
    {
        $read = [$this->socket];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 0, 100000);
        if ($ready === false) {
            throw new RuntimeException('AMI read failed.');
        }
        if ($ready > 0) {
            $chunk = fread($this->socket, 65536);
            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                throw new RuntimeException('AMI disconnected.');
            }
            $this->buffer .= $chunk;
        }
        $frames = [];
        while (($pos = strpos($this->buffer, "\r\n\r\n")) !== false) {
            if ($pos > 65536) {
                throw new RuntimeException('AMI frame too large.');
            }
            $raw = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 4);
            $frame = [];
            foreach (explode("\r\n", $raw) as $line) {
                if (! str_contains($line, ':')) {
                    continue;
                }
                [$key,$value] = explode(':', $line, 2);
                $frame[$key] = ltrim($value);
            }
            if ($frame) {
                $frames[] = $frame;
            }
        }
        if (strlen($this->buffer) > 65536) {
            throw new RuntimeException('AMI incomplete frame too large.');
        }

        return $frames;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
        $this->buffer = '';
    }
}
