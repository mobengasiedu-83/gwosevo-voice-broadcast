<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class GhanaPhone
{
    public static function normalizeImported(string $input): string
    {
        $phone = preg_replace('/[ ()-]/', '', trim($input));
        // Excel numeric cells drop the national trunk prefix, but retain these nine digits.
        if (preg_match('/^[235][0-9]{8}$/', $phone)) {
            $phone = '0'.$phone;
        }

        return self::normalize($phone);
    }

    public static function normalize(string $input): string
    {
        $phone = preg_replace('/[ ()-]/', '', trim($input));
        if (preg_match('/^0[235][0-9]{8}$/', $phone)) {
            $phone = '+233'.substr($phone, 1);
        } elseif (preg_match('/^(?:00)?233[235][0-9]{8}$/', $phone)) {
            $phone = '+'.preg_replace('/^00/', '', $phone);
        }
        if (! preg_match('/^\+233[235][0-9]{8}$/', $phone)) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid Ghana phone number.']);
        }

        return $phone;
    }
}
