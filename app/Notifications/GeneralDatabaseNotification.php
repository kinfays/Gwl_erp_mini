<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GeneralDatabaseNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $title,
        protected string $message,
        protected ?string $url = null,
        protected string $module = 'general',
        protected array $meta = [],
        protected bool $sendMail = false
    ) {}

    public function via($notifiable): array
    {
        return $this->sendMail ? ['database', 'mail'] : ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'module' => $this->module,
            ...$this->meta,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->line($this->message);

        if ($this->url) {
            $mail->action('Open in portal', $this->url);
        }

        return $mail;
    }
}
