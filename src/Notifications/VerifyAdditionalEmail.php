<?php

namespace SecureAccess\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use SecureAccess\Models\UserEmail;

class VerifyAdditionalEmail extends Notification
{
    /**
     * Minutes the verification link stays valid.
     */
    public const EXPIRES = 60;

    public function __construct(public UserEmail $email)
    {
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function verificationUrl(): string
    {
        return URL::temporarySignedRoute('secure-access.emails.verify', now()->addMinutes(self::EXPIRES), [
            'email' => $this->email->getKey(),
            'hash' => $this->email->verificationHash(),
        ]);
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Verify Email Address'))
            ->line(__('Please click the button below to verify this email address. Once verified you can use it to sign in to your account.'))
            ->action(__('Verify Email Address'), $this->verificationUrl())
            ->line(__('If you did not add this email address to an account, no further action is required.'));
    }
}
