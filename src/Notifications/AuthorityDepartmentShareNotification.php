<?php

namespace SecureAccess\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AuthorityDepartmentShareNotification extends Notification
{
    public function __construct(protected string $department) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('New authority information is available'))
            ->greeting(__('Hello!'))
            ->line(__('Authorized material has been shared with your department. Sign in to review it in your secure dashboard.'))
            ->action(__('Open dashboard'), route('secure-access.authority.shares.department', $this->department));
    }
}
