<?php

namespace SecureAccess\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use SecureAccess\Models\PendingRegistration;

class ActivateAccount extends Notification
{
    public const EXPIRES = 60;

    public function __construct(public PendingRegistration $registration)
    {
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function activationUrl(): string
    {
        return URL::temporarySignedRoute('secure-access.activate', $this->registration->expires_at, [
            'registration' => $this->registration->getKey(),
        ]);
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Activate Your Account'))
            ->line(__('Activate your account to choose your username and password and set up multi-factor authentication.'))
            ->action(__('Activate Account'), $this->activationUrl())
            ->line(__('This activation link expires in :minutes minutes.', ['minutes' => self::EXPIRES]))
            ->line(__('If you did not register for an account, no further action is required.'));
    }
}
