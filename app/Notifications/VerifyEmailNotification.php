<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

final class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Confirme seu e-mail')
            ->greeting('Olá!')
            ->line('Confirme seu endereço de e-mail para liberar a compra no marketplace.')
            ->action('Confirmar e-mail', $this->verificationUrl($notifiable))
            ->line("O link expira em {$minutes} minutos. Se expirar, peça outro pelo app.")
            ->line('Se você não criou uma conta, ignore este e-mail.')
            ->salutation('Equipe '.config('app.name'));
    }
}
