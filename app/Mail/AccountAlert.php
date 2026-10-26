<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class AccountAlert extends Mailable
{
    public function __construct(
        public string $alertTitle,
        public string $messageBody,
        public ?string $actionUrl = null,
        public ?string $recipientName = null,
    ) {}

    public function build()
    {
        return $this->subject('GWO SEVO: '.$this->alertTitle)->view('mail.account-alert');
    }
}
