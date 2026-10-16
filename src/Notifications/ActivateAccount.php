<?php

namespace SecureAccess\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use SecureAccess\Models\PendingRegistration;

class ActivateAccount extends Notification
{
    public function __construct(public PendingRegistration $registration, protected string $token)
    {
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function activationUrl(): string
    {
        return URL::temporarySignedRoute('secure-access.register.activate', $this->registration->expires_at, [
            'registration' => $this->registration->getKey(),
            'token' => $this->token,
        ]);
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Activate Your Account'))
            ->greeting(__('Hello :name!', ['name' => $this->registration->name ?: $this->registration->email]))
            ->line(__('Thanks for registering. Please click the button below to activate your account. You will choose your username and password on the activation page.'))
            ->action(__('Activate Account'), $this->activationUrl())
            ->line(__('This activation link expires :time.', ['time' => $this->registration->expires_at->diffForHumans()]))
            ->line(__('If you did not create an account, no further action is required.'));
    }
}
