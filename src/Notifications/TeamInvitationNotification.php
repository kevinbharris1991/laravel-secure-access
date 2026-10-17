<?php

namespace SecureAccess\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SecureAccess\Models\TeamInvitation;

class TeamInvitationNotification extends Notification
{
    public function __construct(
        public TeamInvitation $invitation,
        protected bool $registrationRequired = false,
    ) {
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('A team invitation awaits you'))
            ->greeting(__('Hello!'));

        if ($this->registrationRequired) {
            return $message
                ->line(__('Someone invited you to join their team. Create an account to continue.'))
                ->action(__('Register'), route('secure-access.register'))
                ->line(__('After you register and select the matching account type, you will be connected to their team.'));
        }

        return $message
            ->line(__('Someone invited you to join their team. Sign in to review and respond to the invitation.'))
            ->action(__('Review invitation'), route('secure-access.team.index'));
    }
}
