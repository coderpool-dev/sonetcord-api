<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(private readonly string $resetUrl)
    {
        $this->onConnection('database')->onQueue('background');
    }

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
