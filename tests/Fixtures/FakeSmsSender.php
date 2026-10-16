<?php

namespace SecureAccess\Tests\Fixtures;

use SecureAccess\Contracts\SmsSender;

class FakeSmsSender implements SmsSender
{
    /** @var array<int, array{phone: string, message: string}> */
    public array $messages = [];

    public function send(string $phoneNumber, string $message): void
    {
        $this->messages[] = ['phone' => $phoneNumber, 'message' => $message];
    }

    public function lastCode(): ?string
    {
        $last = end($this->messages);

        return $last && preg_match('/(\d{4,})\s*$/', $last['message'], $m) ? $m[1] : null;
    }
}
