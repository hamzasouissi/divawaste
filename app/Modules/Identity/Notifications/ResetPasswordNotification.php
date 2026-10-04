<?php

namespace App\Modules\Identity\Notifications;

use App\Modules\Common\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.reset_password.subject'))
            ->greeting(__('mail.greeting', ['name' => $notifiable->first_name]))
            ->line(__('mail.reset_password.line'))
            ->action(__('mail.reset_password.action'), FrontendUrl::to('reset-password', ['token' => $this->token, 'email' => $notifiable->email]))
            ->line(__('mail.reset_password.expiry', ['minutes' => config('auth.passwords.users.expire')]));
    }
}
