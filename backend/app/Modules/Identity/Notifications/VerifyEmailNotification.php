<?php

namespace App\Modules\Identity\Notifications;

use App\Modules\Common\Support\FrontendUrl;
use App\Modules\Identity\Support\EmailVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M0-02: confirmation link valid 24 h.
 */
final class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
            ->subject(__('mail.verify_email.subject'))
            ->greeting(__('mail.greeting', ['name' => $notifiable->first_name]))
            ->line(__('mail.verify_email.line'))
            ->action(__('mail.verify_email.action'), FrontendUrl::to('verify-email', EmailVerification::parameters($notifiable)))
            ->line(__('mail.verify_email.expiry', ['hours' => EmailVerification::VALIDITY_HOURS]));
    }
}
