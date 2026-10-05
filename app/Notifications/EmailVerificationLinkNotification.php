<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailVerificationLinkNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $verificationUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Подтверждение почты SonetCord')
            ->greeting('Привет!')
            ->line('Подтвердите почту, чтобы пользоваться SonetCord.')
            ->action('Подтвердить почту', $this->verificationUrl)
            ->line('Ссылка действует 60 минут. Если вы не создавали аккаунт, просто проигнорируйте это письмо.');
    }
}
