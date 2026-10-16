<?php

namespace SecureAccess\Sms;

use Illuminate\Support\Facades\Log;
use SecureAccess\Contracts\SmsSender;

/**
 * Development driver that writes text messages to the log.
 * Bind your own SmsSender implementation (Twilio, Vonage, SNS, ...) in production.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $phoneNumber, string $message): void
    {
        Log::info("[secure-access] SMS to {$phoneNumber}: {$message}");
    }
}
