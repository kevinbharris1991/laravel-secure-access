<?php

namespace SecureAccess\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FinancialApplicationDecisionNotification extends Notification
{
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('Secure dashboard information available'))
            ->line(__('New information is available in your secure dashboard. Sign in to review your currently authorized items.'))
            ->action(__('Open dashboard'), route('secure-access.authority.shares.department', 'finance-accounting'));
    }
}
