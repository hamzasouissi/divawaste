<?php

namespace App\Modules\Tenancy\Notifications;

use App\Modules\Common\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M0-06: the applicant is told by email when the platform approves, rejects, suspends or reactivates the account.
 */
final class CompanyStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'company_approved'|'company_rejected'|'company_suspended'|'company_reactivated'  $event
     */
    public function __construct(
        public readonly string $event,
        private readonly string $companyName,
        private readonly ?string $reason = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__("mail.{$this->event}.subject"))
            ->greeting(__('mail.greeting', ['name' => $notifiable->first_name]))
            ->line(__("mail.{$this->event}.line", ['company' => $this->companyName]));

        if ($this->reason !== null) {
            $mail->line(__("mail.{$this->event}.reason", ['reason' => $this->reason]));
        }

        if (in_array($this->event, ['company_approved', 'company_reactivated'], true)) {
            $mail->action(__("mail.{$this->event}.action"), FrontendUrl::to('login'));
        }

        return $mail;
    }
}
