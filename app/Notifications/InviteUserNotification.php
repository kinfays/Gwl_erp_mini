<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class InviteUserNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $setPasswordUrl,
        public string $staffId
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to GWL Mini Portal - Set Your Password')
            ->greeting('Hello!')
            ->line("An account has been created for you on GWL Mini Portal.")
            ->line("Staff ID: {$this->staffId}")
            ->action('Set Your Password', $this->setPasswordUrl)
            ->line('This link will expire after a short period. If it expires, request a new invite from your administrator.');
    }
}