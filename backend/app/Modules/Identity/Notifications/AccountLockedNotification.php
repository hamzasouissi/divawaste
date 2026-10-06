<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AccountLockedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $minutes) {}

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
            ->subject(__('mail.account_locked.subject'))
            ->greeting(__('mail.greeting', ['name' => $notifiable->first_name]))
            ->line(__('mail.account_locked.line', ['minutes' => $this->minutes]))
            ->line(__('mail.account_locked.advice'));
    }
}
