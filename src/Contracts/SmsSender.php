<?php

namespace SecureAccess\Contracts;

interface SmsSender
{
    /**
     * Send a text message to the given E.164 formatted phone number.
     */
    public function send(string $phoneNumber, string $message): void;
}
