<?php

namespace SecureAccess\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AuthorityReviewNotification extends Notification
{
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('An authority submission has an update'))
            ->greeting(__('Hello!'))
            ->line(__('An authority submission has an update. Sign in to your secure account to review it.'))
            ->action(__('Open your team dashboard'), route('secure-access.team.index'));
    }
}
