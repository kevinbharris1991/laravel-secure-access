<?php

namespace SecureAccess\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationInvitation extends Notification
{
    public function __construct(public string $code)
    {
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You are invited to register'))
            ->line(__('Use the invitation code below when registering with this email address.'))
            ->line($this->code)
            ->action(__('Register'), route('secure-access.register'))
            ->line(__('This invitation expires in :days days.', ['days' => 7]));
    }
}
