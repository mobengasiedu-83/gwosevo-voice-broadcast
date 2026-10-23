<?php

namespace App\Services\Broadcast;

use InvalidArgumentException;

class DialNumber
{
    public static function format(string $e164, string $format): string
    {
        if (! preg_match('/\A\+233[235][0-9]{8}\z/', $e164)) {
            throw new InvalidArgumentException('Invalid Ghana destination.');
        }

        return match ($format) {
            'national' => '0'.substr($e164, 4),
            'international' => substr($e164, 1),
            'e164' => $e164,
            default => throw new InvalidArgumentException('Unsupported trunk number format.'),
        };
    }
}
