<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetLinkNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $resetUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Восстановление пароля SonetCord')
            ->greeting('Привет!')
            ->line('Мы получили запрос на восстановление пароля для вашего аккаунта SonetCord.')
            ->action('Восстановить пароль', $this->resetUrl)
            ->line('Ссылка действует 60 минут. Если вы не запрашивали восстановление, просто проигнорируйте это письмо.');
    }
}
