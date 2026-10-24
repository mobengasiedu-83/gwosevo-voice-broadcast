<?php

namespace App\Services\ClientAuth;

use App\Services\GhanaPhone;
use Illuminate\Validation\ValidationException;

class Identity
{
    public static function parse(string $input): array
    {
        $input = trim($input);
        if (str_contains($input, '@')) {
            if (! filter_var($input, FILTER_VALIDATE_EMAIL) || strlen($input) > 255) {
                throw ValidationException::withMessages(['identifier' => 'Enter a valid email address or Ghana phone number.']);
            }

            return ['email', strtolower($input)];
        }
        try {
            return ['phone', GhanaPhone::normalize($input)];
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['identifier' => 'Enter a valid email address or Ghana phone number.']);
        }
    }

    public static function column(string $channel): string
    {
        return $channel === 'email' ? 'email' : 'phone_e164';
    }
}
