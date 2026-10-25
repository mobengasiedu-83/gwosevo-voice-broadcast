<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class IdentityCode extends Mailable
{
    public function __construct(public string $code, public int $minutes) {}

    public function build()
    {
        return $this->subject('Your GWO SEVO verification code')->view('mail.identity-code');
    }
}
