<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestVitalPbxAmi extends Command
{
    protected $signature = 'vitalpbx:test';

    protected $description = 'Test VitalPBX AMI authentication and Ping';

    public function handle(): int
    {
        $host = config('services.vitalpbx.host');
        $port = (int) config('services.vitalpbx.port', 5038);
        $username = config('services.vitalpbx.username');
        $secret = config('services.vitalpbx.secret');

        if (! is_string($host) || ! is_string($username) || ! is_string($secret) ||
            trim($host) === '' || trim($username) === '' || $secret === '') {
            $this->error('VitalPBX AMI configuration is incomplete.');

            return self::FAILURE;
        }

        if (preg_match('/[\r\n]/', $username.$secret) ||
            ! preg_match('/\A[a-zA-Z0-9.\-:]+\z/', $host) || $port < 1 || $port > 65535) {
            $this->error('Invalid VitalPBX AMI connection configuration.');

            return self::FAILURE;
        }

        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            5
        );

        if (! $socket) {
            $this->error("Connection failed: {$errstr}");

            return self::FAILURE;
        }

        stream_set_timeout($socket, 5);

        try {
            // Consume AMI's initial protocol banner.
            $banner = fgets($socket);

            if (! $banner || ! str_contains($banner, 'Asterisk Call Manager')) {
                $this->error('Unexpected AMI protocol banner.');

                return self::FAILURE;
            }

            $login = "Action: Login\r\n"
                ."Username: {$username}\r\n"
                ."Secret: {$secret}\r\n"
                ."Events: off\r\n\r\n";

            if (fwrite($socket, $login) !== strlen($login)) {
                $this->error('Could not send complete AMI login.');

                return self::FAILURE;
            }

            $response = $this->readResponse($socket);

            if (! $this->isSuccessfulResponse($response)) {
                $this->error('AMI authentication failed.');
                $this->line($this->safeResponse($response));

                return self::FAILURE;
            }

            $this->info('AMI authentication successful.');

            $ping = "Action: Ping\r\n\r\n";

            if (fwrite($socket, $ping) !== strlen($ping)) {
                $this->error('Could not send AMI Ping.');

                return self::FAILURE;
            }

            $response = $this->readResponse($socket);

            if ($this->isSuccessfulResponse($response) && preg_match('/^Ping: Pong\r?$/m', $response)) {
                $this->info('AMI Ping successful.');

                return self::SUCCESS;
            }

            $this->error('AMI Ping failed.');
            $this->line($this->safeResponse($response));

            return self::FAILURE;
        } finally {
            @fwrite($socket, "Action: Logoff\r\n\r\n");
            fclose($socket);
        }
    }

    private function readResponse($socket): string
    {
        $response = '';

        while (! feof($socket)) {
            $line = fgets($socket);

            if ($line === false) {
                break;
            }

            $response .= $line;

            if (trim($line) === '') {
                break;
            }

            if (strlen($response) > 16384) {
                break;
            }
        }

        return $response;
    }

    private function isSuccessfulResponse(string $response): bool
    {
        return str_ends_with($response, "\r\n\r\n")
            && preg_match('/^Response: Success\r?$/m', $response) === 1;
    }

    private function safeResponse(string $response): string
    {
        // Avoid printing arbitrary server-provided content.
        if (str_contains($response, 'Response: Error')) {
            return 'AMI returned an error response.';
        }

        return 'Unexpected AMI response.';
    }
}
