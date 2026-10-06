<?php

namespace App\Modules\Identity\Notifications;

use App\Modules\Common\Support\FrontendUrl;
use App\Modules\Identity\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M0-08. Sent to an address that may not have an account yet (on-demand notifiable).
 */
final class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $companyName,
        private readonly string $inviterName,
        private readonly string $roleLabel,
        private readonly ?string $personalMessage,
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
            ->subject(__('mail.invitation.subject', ['company' => $this->companyName]))
            ->line(__('mail.invitation.line', ['inviter' => $this->inviterName, 'company' => $this->companyName, 'role' => $this->roleLabel]));

        if ($this->personalMessage !== null) {
            $mail->line('« '.$this->personalMessage.' »');
        }

        return $mail
            ->action(__('mail.invitation.action'), FrontendUrl::to('invitation', ['token' => $this->token]))
            ->line(__('mail.invitation.expiry', ['days' => UserInvitation::VALIDITY_DAYS]));
    }
}
