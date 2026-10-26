<?php

namespace App\Console\Commands;

use App\Services\Identity\Challenges;
use App\Services\Identity\Delivery;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class TestIdentitySms extends Command
{
    protected $signature = 'identity:sms-test
        {phone : Recipient mobile number, e.g. 0531005130 or +233531005130}
        {--message= : Text to send instead of the built-in test message}';

    protected $description = 'Send one test SMS through the configured TxtConnect credentials and print the provider result';

    public function handle(Challenges $challenges, Delivery $delivery): int
    {
        try {
            $phone = $challenges->normalize('sms', (string) $this->argument('phone'), 'phone');
        } catch (ValidationException $e) {
            $this->error('The mobile number is not valid. Use a Ghana local number (0531005130) or E.164 (+233531005130).');

            return self::FAILURE;
        }

        $message = trim((string) ($this->option('message') ?: 'This is a GWO SEVO SMS delivery test. No action is required.'));

        try {
            $messageId = $delivery->sms($phone, $message);
        } catch (Throwable $e) {
            $this->error('SMS delivery failed: '.$e->getMessage());
            $this->line('Check TXTCONNECT_API_TOKEN, TXTCONNECT_SENDER_ID (approved sender) and the identity.sms_failed log entry.');

            return self::FAILURE;
        }

        $this->info('TxtConnect accepted the SMS for '.$phone.($messageId !== '' ? ' Message id: '.$messageId : ''));
        $this->line('Acceptance only means the message was queued; confirm it arrived on the handset.');

        return self::SUCCESS;
    }
}
